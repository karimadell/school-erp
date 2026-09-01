<?php

namespace App\Services\Finance;

use App\Models\Fee;
use App\Models\FeeBillingPeriod;
use App\Models\FeePrice;
use App\Models\EnrollmentMode;
use App\Models\Grade;
use App\Models\Invoice;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InvoiceCalculationService
{
    public const CURRENCY = 'EGP';

    /**
     * The tariff-variant dimension fields (Phase 4A.2 canonical pricing
     * rule): two FeePrice rows sharing every one of these, plus fee_id and
     * academic_year_id, are "the same tariff, different date window" — e.g.
     * an early-bird price and its regular successor. Rows differing in any
     * of these are genuinely different tariffs (a different grade, zone,
     * meal plan, or uniform item/size) and must never disambiguate against
     * each other by date.
     */
    private const DIMENSION_FIELDS = ['grade_id', 'grade_group', 'payment_period', 'size', 'item', 'option_type', 'option_value'];

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function calculate(
        array $items,
        ?string $discountType = null,
        string|int|float|null $discountValue = null,
        string|int|float|null $initialPaymentAmount = null,
        ?string $pricingDate = null,
        ?int $academicYearId = null,
    ): array {
        $pricingDate ??= now()->toDateString();
        $feeIds = collect($items)->pluck('fee_id')->map(fn ($id) => (int) $id)->all();

        /** @var Collection<int, Fee> $fees */
        $fees = Fee::query()->whereIn('id', $feeIds)->lockForUpdate()->get()->keyBy('id');

        $lines = [];
        $subtotal = '0.00';
        // Perf (504 investigation, 2026-08-29): every line item in one Quick
        // Registration/invoice submission shares the same enrollment_mode_id
        // — resolvePrice() used to re-run EnrollmentMode::find() for it on
        // every single line. Memoized per calculate() call only (never
        // persisted beyond this request), so a mode change mid-request is
        // still impossible to miss — enrollment_mode_id itself is immutable
        // input for the whole call.
        $modeCache = [];

        foreach ($items as $item) {
            $fee = $fees->get((int) $item['fee_id']);

            if (! $fee) {
                throw ValidationException::withMessages([
                    'fees' => 'Одна из выбранных услуг не найдена.',
                ]);
            }

            if (! $fee->is_active) {
                throw ValidationException::withMessages([
                    'fees' => "Услуга «{$fee->name_ru}» отключена и не может быть добавлена в счёт.",
                ]);
            }

            $resolvedPrice = $this->resolvePrice($fee, $item, $pricingDate, $academicYearId, $modeCache);
            $amount = $resolvedPrice['amount'];
            $quantity = (int) ($item['quantity'] ?? 1);

            if ($quantity < 1) {
                throw ValidationException::withMessages([
                    'services' => 'Количество услуги должно быть не меньше 1.',
                ]);
            }

            if (bccomp($amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages([
                    'fees' => "Для услуги «{$fee->name_ru}» не установлена положительная цена.",
                ]);
            }

            if (($item['first_last_month'] ?? false) === true) {
                $amount = bcmul($amount, '2.00', 2);
            }

            $unitPrice = $amount;
            $amount = bcmul($unitPrice, (string) $quantity, 2);

            $subtotal = bcadd($subtotal, $amount, 2);
            $lines[] = [
                'fee_id' => $fee->id,
                'description' => $fee->name_ru,
                'amount' => $amount,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'grade_group' => $resolvedPrice['metadata']['grade_group'] ?? null,
                'payment_period' => $resolvedPrice['metadata']['payment_period'] ?? null,
                'size' => $resolvedPrice['metadata']['size'] ?? null,
                'item' => $resolvedPrice['metadata']['item'] ?? null,
                'option_type' => $resolvedPrice['metadata']['option_type'] ?? null,
                'option_value' => $resolvedPrice['metadata']['option_value'] ?? null,
                'tariff_valid_from' => $resolvedPrice['valid_from'],
                'tariff_valid_to' => $resolvedPrice['valid_to'],
                'metadata' => $resolvedPrice['metadata'],
            ];
        }

        $discount = $this->discountAmount($subtotal, $discountType, $discountValue);
        $total = bcsub($subtotal, $discount, 2);
        $paid = $this->money($initialPaymentAmount ?? '0');

        if (bccomp($paid, '0.00', 2) < 0) {
            throw ValidationException::withMessages([
                'initial_payment_amount' => 'Первоначальный платёж не может быть отрицательным.',
            ]);
        }

        if (bccomp($paid, $total, 2) > 0) {
            throw ValidationException::withMessages([
                'initial_payment_amount' => 'Первоначальный платёж не может превышать сумму счёта.',
            ]);
        }

        $remaining = bcsub($total, $paid, 2);
        $status = match (true) {
            bccomp($paid, '0.00', 2) === 0 => Invoice::STATUS_UNPAID,
            bccomp($remaining, '0.00', 2) > 0 => Invoice::STATUS_PARTIAL,
            default => Invoice::STATUS_PAID,
        };

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
            'status' => $status,
            'currency' => self::CURRENCY,
            'line_items' => $lines,
        ];
    }

    /**
     * @param  array<string, mixed>  $selection
     * @param  array<int, ?EnrollmentMode>  $modeCache  Keyed by enrollment_mode_id,
     *         shared across every line item in the same calculate() call —
     *         see the perf note at its call site.
     * @return array{amount:string, valid_from:?string, valid_to:?string, metadata:array<string, mixed>}
     */
    private function resolvePrice(Fee $fee, array $selection, string $date, ?int $academicYearId, array &$modeCache = []): array
    {
        if (filled($selection['fee_price_id'] ?? null)) {
            $price = FeePrice::query()->lockForUpdate()->find((int) $selection['fee_price_id']);
            $valid = $price
                && $price->fee_id === $fee->id
                && $price->is_active
                && $price->currency === self::CURRENCY
                && (! $academicYearId || $price->academic_year_id === $academicYearId)
                && $this->isUsable($price, $date);

            foreach (['grade_group', 'payment_period', 'size', 'item', 'option_type', 'option_value'] as $field) {
                if ($valid && filled($price->{$field}) && (string) ($selection[$field] ?? '') !== (string) $price->{$field}) {
                    $valid = false;
                }
            }

            if (! $valid) {
                throw ValidationException::withMessages(['fees' => "Выбранный тариф не принадлежит услуге «{$fee->name_ru}» или недействителен."]);
            }

            return [
                'amount' => $this->money($price->getRawOriginal('amount')),
                'valid_from' => $price->start_date?->toDateString(),
                'valid_to' => $price->end_date?->toDateString(),
                'metadata' => $this->priceMetadata($price, $date),
            ];
        }

        $requiredContext = match ($fee->category) {
            Fee::CATEGORY_TRANSPORT => ['option_type', 'option_value'],
            Fee::CATEGORY_FOOD => ['option_type', 'option_value'],
            Fee::CATEGORY_UNIFORM => ['size', 'item'],
            default => [],
        };
        if ($fee->category === Fee::CATEGORY_TRANSPORT
            && $fee->prices()->whereNotNull('payment_period')->exists()) {
            $requiredContext[] = 'payment_period';
        }
        foreach ($requiredContext as $field) {
            if (blank($selection[$field] ?? null) && $fee->prices()->whereNotNull($field)->exists()) {
                throw ValidationException::withMessages([
                    'fees' => "Для услуги «{$fee->name_ru}» выберите все параметры тарифа.",
                ]);
            }
        }

        $candidates = $this->dimensionalCandidates($fee, $selection, $academicYearId, $modeCache);
        $price = $this->selectAmongCandidates($candidates, $date);
        $derived = null;

        // Finance V2, Phase 2D — quarterly derived pricing. Only when no
        // explicit quarterly tariff resolved above, and only when this Fee
        // is actually configured to allow quarterly billing at all
        // (defense-in-depth: Phase 2B's request/service-level validation
        // should already prevent 'quarterly' being requested for a Fee
        // that doesn't allow it, but the resolver itself must never derive
        // a price the Fee isn't even eligible to be billed under).
        if (! $price && ($selection['payment_period'] ?? null) === 'quarterly'
            && $fee->allowsBillingPeriod(FeeBillingPeriod::PERIOD_QUARTERLY)) {
            $monthlySelection = array_merge($selection, ['payment_period' => 'monthly']);
            $monthlyCandidates = $this->dimensionalCandidates($fee, $monthlySelection, $academicYearId, $modeCache);
            $monthlyPrice = $this->selectAmongCandidates($monthlyCandidates, $date);
            if ($monthlyPrice) {
                $monthlyAmount = $this->money($monthlyPrice->getRawOriginal('amount'));
                $derived = [
                    'amount' => bcmul($monthlyAmount, '3', 2),
                    'monthly_price' => $monthlyPrice,
                    'monthly_amount' => $monthlyAmount,
                ];
            }
        }

        // Transport/food/uniform pricing is structurally dimensional (zone /
        // meal plan / item+size) — a flat Fee.amount/base_price fallback
        // would silently create a phantom, unpriced-in-reality line the
        // moment the fee has zero FeePrice rows at all (as opposed to some
        // rows that simply don't match this selection). These categories
        // must always resolve through a real dimensional tariff or fail
        // loudly, even when the fee has never had a single price configured.
        $requiresDimensionalTariff = in_array($fee->category, [
            Fee::CATEGORY_TRANSPORT, Fee::CATEGORY_FOOD, Fee::CATEGORY_UNIFORM,
        ], true);
        // Phase 2D: an explicit quarterly request that resolved neither a
        // real quarterly tariff nor a derivable monthly one must always
        // fail loud — never silently fall through to the flat
        // Fee.amount/base_price fallback that non-dimensional categories
        // (e.g. Tuition with zero configured FeePrice rows) would
        // otherwise use for every other period.
        $quarterlyRequested = ($selection['payment_period'] ?? null) === 'quarterly';
        if (! $price && ! $derived && $academicYearId && ($fee->prices()->exists() || $requiresDimensionalTariff || $quarterlyRequested)) {
            throw ValidationException::withMessages([
                'fees' => 'На выбранную дату тариф не настроен.',
            ]);
        }

        if ($derived) {
            $metadata = $this->priceMetadata($derived['monthly_price'], $date);
            $metadata['payment_period'] = 'quarterly';
            $metadata['derived'] = true;
            $metadata['derived_period'] = 'quarterly';
            $metadata['derived_from_period'] = 'monthly';
            $metadata['derived_from_fee_price_id'] = $derived['monthly_price']->id;
            $metadata['monthly_unit_amount'] = $derived['monthly_amount'];
            $metadata['multiplier'] = '3';

            return [
                'amount' => $derived['amount'],
                'valid_from' => $derived['monthly_price']->start_date?->toDateString(),
                'valid_to' => $derived['monthly_price']->end_date?->toDateString(),
                'metadata' => $metadata,
            ];
        }

        return [
            'amount' => $this->money($price?->getRawOriginal('amount') ?? $fee->getRawOriginal('amount') ?? $fee->getRawOriginal('base_price') ?? '0'),
            'valid_from' => $price?->start_date?->toDateString(),
            'valid_to' => $price?->end_date?->toDateString(),
            'metadata' => $price ? $this->priceMetadata($price, $date) : ['pricing_date' => $date],
        ];
    }

    /**
     * The dimensional (non-explicit-fee_price_id) candidate query, factored
     * out of resolvePrice() so Phase 2D's quarterly-derivation fallback can
     * re-run the identical dimensional filtering with only payment_period
     * substituted — same effective-date rules, same grade/zone/item/size
     * matching, never a looser or different query for the derived path.
     *
     * @param  array<string, mixed>  $selection
     * @param  array<int, ?EnrollmentMode>  $modeCache
     * @return Collection<int, FeePrice>
     */
    private function dimensionalCandidates(Fee $fee, array $selection, ?int $academicYearId, array &$modeCache = []): Collection
    {
        $query = FeePrice::query()
            ->where('fee_id', $fee->id)
            ->active()->where('currency', self::CURRENCY)
            ->when($academicYearId, fn ($query) => $query->where('academic_year_id', $academicYearId));

        $gradeGroup = $selection['grade_group'] ?? $this->gradeGroupFor($selection['grade_id'] ?? null);
        if (filled($selection['grade_group'] ?? null)) {
            $query->where('grade_group', $selection['grade_group']);
        } elseif (filled($selection['grade_id'] ?? null)) {
            $query->where(function ($query) use ($selection, $gradeGroup) {
                $query->where('grade_id', (int) $selection['grade_id']);
                if ($gradeGroup) {
                    $query->orWhere(fn ($query) => $query->whereNull('grade_id')->where('grade_group', $gradeGroup));
                }
                $query->orWhere(fn ($query) => $query->whereNull('grade_id')->whereNull('grade_group'));
            });
        }

        foreach (['payment_period', 'size', 'item'] as $field) {
            if (filled($selection[$field] ?? null)) {
                $query->where($field, $selection[$field]);
            }
        }

        if (filled($selection['option_value'] ?? null)) {
            $query->where('option_value', $selection['option_value']);
            filled($selection['option_type'] ?? null)
                ? $query->where('option_type', $selection['option_type'])
                : $query->whereNull('option_type');
        } elseif (filled($selection['enrollment_mode_id'] ?? null)) {
            $modeId = (int) $selection['enrollment_mode_id'];
            $mode = array_key_exists($modeId, $modeCache) ? $modeCache[$modeId] : ($modeCache[$modeId] = EnrollmentMode::find($modeId));
            $modeTypes = ['enrollment_mode', 'Форма', 'Форма обучения'];
            $modeValues = collect([$mode?->code, $mode?->name_ru, $mode?->short_name_ru])->filter()->unique()->values();
            $hasModePrices = (clone $query)->whereIn('option_type', $modeTypes)->exists();
            if ($hasModePrices) {
                $query->whereIn('option_type', $modeTypes)->whereIn('option_value', $modeValues);
            }
        }

        // Phase 4A.2 canonical pricing rule: academic_year_id is the
        // primary ownership boundary for a tariff, not the calendar date.
        // Fetch every same-fee/same-year/same-selection candidate first —
        // if there is exactly one, it is usable even before its own
        // start_date (a parent may prepay for a year before classes
        // start). Only when several same-year candidates exist (early
        // bird / staged increases / promotions) does the date range
        // disambiguate between them; a date matching none of them fails
        // loudly rather than guessing (see selectAmongCandidates()).
        return $query
            ->when(filled($selection['grade_id'] ?? null), fn ($query) => $query
                ->orderByRaw('CASE WHEN grade_id = ? THEN 0 WHEN grade_group IS NOT NULL THEN 1 ELSE 2 END', [(int) $selection['grade_id']]))
            ->orderByDesc('start_date')->orderByDesc('id')->lockForUpdate()->get();
    }

    /**
     * Given every same-fee/same-year/same-selection FeePrice candidate
     * (already dimensionally filtered by the caller — active, EGP, correct
     * academic_year_id, matching grade/zone/meal-plan/item-size — but not
     * yet date-filtered), decides which one (if any) the resolver should
     * actually use as of $date.
     *
     * @param  Collection<int, FeePrice>  $candidates  Ordered by the
     *         caller's own precedence (most specific / most recent first)
     *         — preserved through filtering so ties still resolve the same
     *         way they always did.
     */
    private function selectAmongCandidates(Collection $candidates, string $date): ?FeePrice
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            $only = $candidates->first();

            // A sole candidate is usable even before its own start_date
            // (prepayment) — but staleness (already past its own end_date)
            // is never silently resurrected; that is not a prepayment case.
            return ($only->end_date && $only->end_date->toDateString() < $date) ? null : $only;
        }

        // Several same-year candidates for the identical selection (early
        // bird / staged increase / promotion) — the date range picks the
        // one whose window actually contains $date. Nothing matching is a
        // real gap (a date before every window, or between two), and must
        // fail rather than guess.
        return $candidates->first(fn (FeePrice $price) => $price->start_date?->toDateString() <= $date
            && (! $price->end_date || $price->end_date->toDateString() >= $date));
    }

    /**
     * Whether an explicitly chosen FeePrice (the fee_price_id selection
     * path) is usable as of $date under the same rule selectAmongCandidates()
     * applies: within its own window, or — when it is the only same-year,
     * same-dimension tariff for this fee — usable even before its
     * start_date.
     */
    private function isUsable(FeePrice $price, string $date): bool
    {
        $withinWindow = $price->start_date?->toDateString() <= $date
            && (! $price->end_date || $price->end_date->toDateString() >= $date);
        if ($withinWindow) {
            return true;
        }

        $notYetStarted = $price->start_date && $price->start_date->toDateString() > $date;

        return $notYetStarted && ! $this->hasDimensionSibling($price);
    }

    /** Any other active, EGP, same-fee/same-year tariff sharing every dimension field with $price. */
    private function hasDimensionSibling(FeePrice $price): bool
    {
        $query = FeePrice::query()
            ->where('fee_id', $price->fee_id)
            ->where('academic_year_id', $price->academic_year_id)
            ->where('id', '!=', $price->id)
            ->active()->where('currency', self::CURRENCY);

        foreach (self::DIMENSION_FIELDS as $field) {
            $price->{$field} === null ? $query->whereNull($field) : $query->where($field, $price->{$field});
        }

        return $query->exists();
    }

    /**
     * The canonical "which of these rows would the resolver actually use
     * right now" filter — the single source FinanceConfigurationReadinessService,
     * price-preview listings, and the UAT readiness audit command all
     * compose instead of re-deriving this rule themselves.
     *
     * @param  Collection<int, FeePrice>  $prices  Active, EGP rows already
     *         scoped to one academic year (any mix of fees/dimensions —
     *         grouping below is fee_id + academic_year_id + dimension-safe).
     * @return Collection<int, FeePrice>
     */
    public function resolvableCandidates(Collection $prices, string $date): Collection
    {
        return $prices
            ->groupBy(fn (FeePrice $price) => $this->dimensionSignature($price))
            ->map(fn (Collection $group) => $this->selectAmongCandidates($group, $date))
            ->filter()
            ->values();
    }

    private function dimensionSignature(FeePrice $price): string
    {
        return $price->academic_year_id.'|'.$price->fee_id.'|'.collect(self::DIMENSION_FIELDS)
            ->map(fn ($field) => (string) ($price->{$field} ?? ''))
            ->implode('|');
    }

    /** @return array<string, mixed> */
    private function priceMetadata(FeePrice $price, string $pricingDate): array
    {
        return collect([
            'fee_price_id' => $price->id,
            'academic_year_id' => $price->academic_year_id,
            'currency' => $price->currency,
            'pricing_date' => $pricingDate,
            'tariff_valid_from' => $price->start_date?->toDateString(),
            'tariff_valid_to' => $price->end_date?->toDateString(),
            'grade_id' => $price->grade_id,
            'grade_group' => $price->grade_group,
            'payment_period' => $price->payment_period,
            'size' => $price->size,
            'item' => $price->item,
            'option_type' => $price->option_type,
            'option_value' => $price->option_value,
        ])->reject(fn ($value) => $value === null || $value === '')->all();
    }

    private function gradeGroupFor(mixed $gradeId): ?string
    {
        if (! filled($gradeId)) {
            return null;
        }

        return match (Grade::find((int) $gradeId)?->level) {
            0 => 'Подготовительный класс',
            1, 2, 3, 4 => '1–4 классы',
            5, 6 => '5–6 классы',
            7, 8 => '7–8 классы',
            9, 10, 11 => '9–11 классы',
            default => null,
        };
    }

    private function discountAmount(string $subtotal, ?string $type, string|int|float|null $value): string
    {
        if ($type === null || $type === '') {
            return '0.00';
        }

        $amount = $this->money($value ?? '0');

        if (bccomp($amount, '0.00', 2) < 0) {
            throw ValidationException::withMessages(['discount_value' => 'Скидка не может быть отрицательной.']);
        }

        if ($type === 'percent') {
            if (bccomp($amount, '100.00', 2) > 0) {
                throw ValidationException::withMessages(['discount_value' => 'Процентная скидка не может превышать 100%.']);
            }

            return $this->roundMoney(bcdiv(bcmul($subtotal, $amount, 6), '100.00', 6));
        }

        if ($type !== 'fixed') {
            throw ValidationException::withMessages(['discount_type' => 'Выбран недопустимый тип скидки.']);
        }

        if (bccomp($amount, $subtotal, 2) > 0) {
            throw ValidationException::withMessages(['discount_value' => 'Фиксированная скидка не может превышать сумму услуг.']);
        }

        return $amount;
    }

    private function money(string|int|float $value): string
    {
        $value = is_float($value) ? number_format($value, 4, '.', '') : (string) $value;

        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Денежная сумма указана в неверном формате.']);
        }

        return $this->roundMoney($value);
    }

    private function roundMoney(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;
        $rounded = bcadd($absolute, '0.005', 2);

        return $negative && bccomp($rounded, '0.00', 2) !== 0
            ? '-' . $rounded
            : $rounded;
    }
}
