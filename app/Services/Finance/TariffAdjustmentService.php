<?php

namespace App\Services\Finance;

use App\Models\FeePrice;
use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ServiceCoverage;
use App\Models\StudentCredit;
use App\Models\TariffAdjustment;
use App\Models\TariffAdjustmentSegment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TariffAdjustmentService
{
    public function __construct(
        private InstallmentPlanService $installments,
        private FoodBillableDayCalculator $foodDays,
    ) {}

    public function previewAffected(FeePrice $newPrice): \Illuminate\Support\Collection
    {
        return ServiceCoverage::with(['student', 'fee', 'feePrice', 'invoiceItem.invoice'])
            ->where('fee_id', $newPrice->fee_id)
            ->get()
            ->filter(function (ServiceCoverage $coverage) use ($newPrice): bool {
                try {
                    $preview = $this->preview($coverage, $newPrice);

                    return $preview['units'] > 0 && bccomp($preview['total_difference'], '0.00', 2) !== 0;
                } catch (ValidationException) {
                    return false;
                }
            })
            ->map(fn (ServiceCoverage $coverage): array => $this->preview($coverage, $newPrice))
            ->values();
    }

    public function preview(ServiceCoverage $coverage, FeePrice $newPrice): array
    {
        $this->validatePrice($coverage, $newPrice);
        if ($coverage->fee?->category === Fee::CATEGORY_FOOD
            && collect($coverage->metadata['food_tariff_segments'] ?? [])->contains(
                fn (array $segment) => (int) ($segment['fee_price_id'] ?? 0) === $newPrice->id
            )) {
            return $this->result($coverage, $newPrice, $newPrice, null, 0, '0.00', '0.00');
        }
        $prices = $this->canonicalPrices($coverage);
        $previous = (clone $prices)
            ->whereDate('start_date', '<', $newPrice->start_date)
            ->orderByDesc('start_date')->orderByDesc('id')->first();
        $previous ??= $coverage->feePrice;
        if (! $previous) {
            throw ValidationException::withMessages(['new_fee_price_id' => 'Для покрытия отсутствует исходный тариф.']);
        }

        $next = (clone $prices)
            ->whereDate('start_date', '>', $newPrice->start_date)
            ->orderBy('start_date')->orderBy('id')->first();
        $start = Carbon::parse($coverage->coverage_start)->max(Carbon::parse($newPrice->start_date));
        $end = Carbon::parse($coverage->coverage_end);
        if ($newPrice->end_date) {
            $end = $end->min(Carbon::parse($newPrice->end_date));
        }
        if ($next) {
            $end = $end->min(Carbon::parse($next->start_date)->subDay());
        }

        if ($start->gt($end)) {
            return $this->result($coverage, $previous, $newPrice, null, 0, '0.00', '0.00');
        }

        $units = match ($coverage->billing_unit) {
            'monthly' => $start->copy()->startOfMonth()->diffInMonths($end->copy()->startOfMonth()) + 1,
            'daily' => $coverage->fee?->category === Fee::CATEGORY_FOOD
                ? $this->foodDays->calculate(
                    AcademicYear::findOrFail($newPrice->academic_year_id),
                    $start->toDateString(),
                    $end->toDateString(),
                    requireBillableDay: false,
                )['billable_day_count']
                : $start->diffInDays($end) + 1,
            default => throw ValidationException::withMessages(['billing_unit' => 'Единица покрытия не поддерживает перерасчёт.']),
        };
        $difference = bcsub((string) $newPrice->amount, (string) $previous->amount, 2);
        $total = bcmul($difference, (string) $units, 2);

        return $this->result($coverage, $previous, $newPrice, [$start->toDateString(), $end->toDateString()], $units, $difference, $total);
    }

    public function approve(ServiceCoverage $coverage, FeePrice $newPrice, User $actor, ?string $note = null): ?TariffAdjustment
    {
        return DB::transaction(function () use ($coverage, $newPrice, $actor, $note): ?TariffAdjustment {
            $coverage = ServiceCoverage::query()->lockForUpdate()->findOrFail($coverage->id);
            $newPrice = FeePrice::query()->lockForUpdate()->findOrFail($newPrice->id);
            $preview = $this->preview($coverage, $newPrice);
            if ($preview['units'] === 0 || bccomp($preview['total_difference'], '0.00', 2) === 0) {
                return null;
            }

            $existing = TariffAdjustment::query()
                ->where('service_coverage_id', $coverage->id)
                ->where('previous_fee_price_id', $preview['previous_fee_price']->id)
                ->where('new_fee_price_id', $newPrice->id)->first();
            if ($existing) {
                return $existing->load('segments');
            }

            [$segmentStart, $segmentEnd] = $preview['segment'];
            if (TariffAdjustmentSegment::query()->where('service_coverage_id', $coverage->id)
                ->whereDate('segment_start', '<=', $segmentEnd)->whereDate('segment_end', '>=', $segmentStart)->exists()) {
                throw ValidationException::withMessages(['new_fee_price_id' => 'Период корректировки пересекается с уже проведённым экономическим сегментом.']);
            }

            $invoice = bccomp($preview['total_difference'], '0.00', 2) > 0
                ? $this->postDebitInvoice($coverage, $preview, $actor)
                : null;
            $adjustment = TariffAdjustment::create([
                'student_id' => $coverage->student_id,
                'fee_id' => $coverage->fee_id,
                'service_coverage_id' => $coverage->id,
                'previous_fee_price_id' => $preview['previous_fee_price']->id,
                'new_fee_price_id' => $newPrice->id,
                'status' => TariffAdjustment::STATUS_POSTED,
                'kind' => bccomp($preview['total_difference'], '0.00', 2) > 0 ? 'debit' : 'credit',
                'total_difference' => $preview['total_difference'],
                'currency' => 'EGP',
                'posting_invoice_id' => $invoice?->id,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'note' => $note,
            ]);
            TariffAdjustmentSegment::create([
                'tariff_adjustment_id' => $adjustment->id,
                'service_coverage_id' => $coverage->id,
                'previous_fee_price_id' => $preview['previous_fee_price']->id,
                'new_fee_price_id' => $newPrice->id,
                'segment_start' => $segmentStart,
                'segment_end' => $segmentEnd,
                'billing_unit' => $coverage->billing_unit,
                'units' => $preview['units'],
                'previous_unit_price' => $preview['previous_unit_price'],
                'new_unit_price' => $preview['new_unit_price'],
                'difference_per_unit' => $preview['difference_per_unit'],
                'total_difference' => $preview['total_difference'],
            ]);
            if ($adjustment->kind === 'credit') {
                $amount = ltrim((string) $adjustment->total_difference, '-');
                StudentCredit::create([
                    'student_id' => $coverage->student_id, 'source_adjustment_id' => $adjustment->id,
                    'original_amount' => $amount, 'consumed_amount' => '0.00', 'available_amount' => $amount,
                    'status' => StudentCredit::STATUS_AVAILABLE,
                ]);
            }

            return $adjustment->load('segments');
        });
    }

    private function postDebitInvoice(ServiceCoverage $coverage, array $preview, User $actor): Invoice
    {
        $amount = $preview['total_difference'];
        $price = $preview['new_fee_price'];
        $invoice = Invoice::create([
            'student_id' => $coverage->student_id,
            'academic_year_id' => $price->academic_year_id,
            'customer_name' => $coverage->student->full_name,
            'currency' => 'EGP', 'subtotal_amount' => $amount, 'total_amount' => $amount,
            'discount_amount' => '0.00', 'paid_amount' => '0.00', 'remaining_amount' => $amount,
            'status' => Invoice::STATUS_UNPAID, 'due_date' => now()->toDateString(),
            'note' => 'Корректировка тарифа', 'created_by' => $actor->id,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, now()->format('Y'));
        $invoice->save();
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'fee_id' => $coverage->fee_id,
            'description' => 'Доплата по изменению тарифа: '.$coverage->fee->name_ru,
            'unit_price' => $preview['difference_per_unit'], 'quantity' => $preview['units'],
            'amount' => $amount, 'paid_amount' => '0.00', 'remaining_amount' => $amount,
            'metadata' => ['coverage_id' => $coverage->id, 'segment_start' => $preview['segment'][0], 'segment_end' => $preview['segment'][1]],
        ]);
        $this->installments->generateSingle($invoice, now()->toDateString());

        return $invoice;
    }

    private function validatePrice(ServiceCoverage $coverage, FeePrice $price): void
    {
        if ($price->fee_id !== $coverage->fee_id || ! $price->is_active || $price->currency !== 'EGP') {
            throw ValidationException::withMessages(['new_fee_price_id' => 'Новый тариф не соответствует услуге покрытия.']);
        }
        if ($coverage->feePrice && $price->academic_year_id !== $coverage->feePrice->academic_year_id) {
            throw ValidationException::withMessages(['new_fee_price_id' => 'Тариф относится к другому учебному году.']);
        }
        foreach (['payment_period', 'option_type', 'option_value', 'grade_group', 'item', 'size'] as $field) {
            if (($price->{$field} ?? null) !== ($coverage->{$field} ?? null)) {
                throw ValidationException::withMessages(['new_fee_price_id' => 'Измерения нового тарифа не совпадают с покрытием ученика.']);
            }
        }
    }

    private function matchingPrices(ServiceCoverage $coverage): Builder
    {
        return FeePrice::query()->where('fee_id', $coverage->fee_id)
            ->where('academic_year_id', $coverage->feePrice?->academic_year_id)
            ->where('currency', 'EGP')->where('is_active', true)
            ->where(function (Builder $query) use ($coverage): void {
                foreach (['payment_period', 'option_type', 'option_value', 'grade_group', 'item', 'size'] as $field) {
                    is_null($coverage->{$field}) ? $query->whereNull($field) : $query->where($field, $coverage->{$field});
                }
            });
    }

    private function canonicalPrices(ServiceCoverage $coverage): Builder
    {
        $prices = $this->matchingPrices($coverage);
        $ambiguousStart = (clone $prices)->select('start_date')->groupBy('start_date')->havingRaw('COUNT(*) > 1')->exists();
        if ($ambiguousStart) {
            throw ValidationException::withMessages(['new_fee_price_id' => 'История тарифов неоднозначна: несколько версий имеют одну дату начала.']);
        }
        $ordered = (clone $prices)->orderBy('start_date')->get(['id', 'start_date', 'end_date']);
        for ($index = 0; $index < $ordered->count() - 1; $index++) {
            $current = $ordered[$index];
            $following = $ordered[$index + 1];
            if (! $current->end_date || $current->end_date->gte($following->start_date)) {
                throw ValidationException::withMessages(['new_fee_price_id' => 'История тарифов неоднозначна: интервалы версий пересекаются.']);
            }
        }

        return $prices;
    }

    private function result(ServiceCoverage $coverage, FeePrice $previous, FeePrice $new, ?array $segment, int $units, string $difference, string $total): array
    {
        return [
            'coverage' => $coverage, 'previous_fee_price' => $previous, 'new_fee_price' => $new,
            'segment' => $segment, 'units' => $units,
            'previous_unit_price' => (string) $previous->amount,
            'new_unit_price' => (string) $new->amount,
            'difference_per_unit' => $difference, 'total_difference' => $total,
        ];
    }
}
