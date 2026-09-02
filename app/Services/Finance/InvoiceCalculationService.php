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

    public function __construct(private CalendarPeriodCalculator $periods)
    {
    }

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
     * Finance V2, Phase 2D corrective pass #3 (P0 Blocker 2 — complete
     * canonical tariff-dimension validation, everywhere). The single
     * source of truth for "which option_type values mean this tariff is
     * scoped by enrollment mode rather than a literal zone/plan/item" —
     * both dimensionalCandidates() (automatic resolution) and
     * explicitPriceMatchesSelection() (the explicit-fee_price_id branch)
     * consult this SAME constant, so the two can never independently
     * drift on which option_type values carry that meaning.
     */
    private const MODE_OPTION_TYPES = ['enrollment_mode', 'Форма', 'Форма обучения'];

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  ?string  $calendarBillingPeriod  Finance V2, Phase 2D corrective
     *         pass (P0 Blocker 1): when the invoice is being issued under
     *         Phase 2B's calendar payment_type (monthly/quarterly/yearly),
     *         every line's resolved UNIT price must be multiplied by the
     *         number of covered periods before it becomes the invoice
     *         total — previously this multiplication never happened
     *         anywhere, so a 1500/month Transport line billed for 9 months
     *         was invoiced at 1500 total instead of 13500. Null for every
     *         non-calendar caller (one_time, custom plan), preserving their
     *         existing behaviour exactly (quantity stays whatever the
     *         caller submits, default 1).
     * @param  ?string  $academicYearEndDate  Required together with
     *         $calendarBillingPeriod — the same academic-year end date
     *         InstallmentPlanService::generateCalendarSchedule() uses, so
     *         pricing and scheduling count the exact same number of
     *         periods via the same CalendarPeriodCalculator.
     * @return array<string, mixed>
     */
    public function calculate(
        array $items,
        ?string $discountType = null,
        string|int|float|null $discountValue = null,
        string|int|float|null $initialPaymentAmount = null,
        ?string $pricingDate = null,
        ?int $academicYearId = null,
        ?string $calendarBillingPeriod = null,
        ?string $academicYearEndDate = null,
    ): array {
        $pricingDate ??= now()->toDateString();
        $feeIds = collect($items)->pluck('fee_id')->map(fn ($id) => (int) $id)->all();

        // Phase 2D corrective pass: resolved ONCE for the whole submission
        // (every line on a calendar-billed invoice shares the same
        // registration date and billing period — M1's own rule already
        // requires every Fee on one invoice to share one billing strategy,
        // so there is exactly one period count for the whole calculate()
        // call, never a per-line count).
        $calendarPeriods = null;
        if ($calendarBillingPeriod !== null) {
            if ($academicYearEndDate === null) {
                throw ValidationException::withMessages(['billing_period' => 'Не указана дата окончания учебного года для расчёта периодов.']);
            }
            $calendarPeriods = $this->periods->resolve($calendarBillingPeriod, $pricingDate, $academicYearEndDate);
        }

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
            $submittedQuantity = (int) ($item['quantity'] ?? 1);

            if (bccomp($resolvedPrice['amount'], '0.00', 2) <= 0) {
                throw ValidationException::withMessages([
                    'fees' => "Для услуги «{$fee->name_ru}» не установлена положительная цена.",
                ]);
            }

            $baseAmount = $resolvedPrice['amount'];
            if (($item['first_last_month'] ?? false) === true) {
                $baseAmount = bcmul($baseAmount, '2.00', 2);
            }

            // Deliberately excludes a 'daily'-denominated resolved price
            // (Food's own genuine per-day tariff) from the multi-period
            // UNIT-pricing logic below — multiplying a daily rate by a
            // MONTH/quarter count would be exactly the invented,
            // unsupported monthly-to-daily conversion explicitly
            // forbidden for this phase. A daily-priced line's own charge
            // stays untouched (whatever the caller submits), but — since
            // it still rides the SAME shared installment schedule as
            // every other Fee on this invoice (M1) — its amount is still
            // spread evenly across that schedule's groups below, exactly
            // like every line did before this corrective pass (an
            // unrelated, pre-existing scheduling behavior for Food this
            // pass does not change).
            $isDailyPriced = ($resolvedPrice['metadata']['payment_period'] ?? null) === 'daily';
            $lineMetadata = $resolvedPrice['metadata'];
            $groupAmounts = null;

            if ($calendarPeriods !== null && ! $isDailyPriced) {
                if ($calendarBillingPeriod === 'quarterly') {
                    $priced = $this->priceQuarterlyLine($fee, $item, $resolvedPrice, $baseAmount, $calendarPeriods, $pricingDate, $academicYearId);
                } else {
                    // Monthly/yearly: every group is uniform (1 month, or
                    // the single yearly span), so a flat unit x count is
                    // both correct and identical to the per-group
                    // breakdown collapsed to one number.
                    $count = $calendarPeriods['count'];
                    $priced = [
                        'unit_price' => $baseAmount,
                        'quantity' => $count,
                        'amount' => bcmul($baseAmount, (string) $count, 2),
                        'group_amounts' => array_fill(0, count($calendarPeriods['periods']), $baseAmount),
                        'metadata' => [],
                    ];
                }

                $unitPrice = $priced['unit_price'];
                $quantity = $priced['quantity'];
                $amount = $priced['amount'];
                $groupAmounts = $priced['group_amounts'];
                $lineMetadata = array_merge($lineMetadata, $priced['metadata']);

                // A caller asserting a quantity this billing mode cannot
                // express (anything other than the unremarkable default
                // of 1, or — harmlessly — the value the system itself
                // just computed) is rejected loudly rather than silently
                // overridden or silently honoured, which would either
                // mask a real client bug or double-count periods.
                if ($submittedQuantity !== 1 && $submittedQuantity !== $quantity) {
                    throw ValidationException::withMessages([
                        'services' => "Количество для услуги «{$fee->name_ru}» определяется периодом оплаты и не может быть указано вручную.",
                    ]);
                }

                // Auditable trace of the multi-period pricing computation,
                // same storage convention as the quarterly-derivation
                // metadata keys (derived/derived_period/etc) already
                // established earlier in this same phase.
                $lineMetadata['unit_tariff'] = $unitPrice;
                $lineMetadata['billing_unit'] = $calendarBillingPeriod;
                $lineMetadata['unit_count'] = (string) $quantity;
                $lineMetadata['coverage_start'] = $calendarPeriods['periods'][0]['start'];
                $lineMetadata['coverage_end'] = $calendarPeriods['periods'][array_key_last($calendarPeriods['periods'])]['end'];
                $lineMetadata['line_total'] = $amount;
            } elseif ($calendarPeriods !== null) {
                // Food (daily-priced) on a calendar-billed invoice: charge
                // amount is untouched (no period multiplication), but its
                // contribution to the SHARED installment schedule is
                // still spread evenly across every group — the same
                // even-split-with-last-absorbing-remainder rule the whole
                // invoice used to apply globally, now scoped to just this
                // line's own contribution.
                $quantity = max(1, $submittedQuantity);
                $unitPrice = $baseAmount;
                $amount = bcmul($unitPrice, (string) $quantity, 2);

                $groupCount = count($calendarPeriods['periods']);
                $each = bcdiv($amount, (string) $groupCount, 2);
                $allocated = '0.00';
                $groupAmounts = [];
                foreach (range(0, $groupCount - 1) as $i) {
                    $g = $i === $groupCount - 1 ? bcsub($amount, $allocated, 2) : $each;
                    $allocated = bcadd($allocated, $g, 2);
                    $groupAmounts[] = $g;
                }
            } else {
                $quantity = $submittedQuantity;
                if ($quantity < 1) {
                    throw ValidationException::withMessages([
                        'services' => 'Количество услуги должно быть не меньше 1.',
                    ]);
                }
                $unitPrice = $baseAmount;
                $amount = bcmul($unitPrice, (string) $quantity, 2);
            }

            // Corrective pass #4 (HIGH 1 — calendar discount reconciliation):
            // $scheduleAmounts is no longer accumulated here, from the
            // PRE-discount $groupAmounts — it is rebuilt once, below,
            // from each line's own FINAL (post-discount) period_amounts,
            // after discount allocation runs. Accumulating it here would
            // silently leave the installment schedule (and therefore
            // coverage-period "full settlement" amounts) at the
            // pre-discount total forever, exactly the confirmed real bug
            // this pass fixes.
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
                'metadata' => $lineMetadata,
                // Corrective pass #2 (P0 Blocker 2 — payment-to-coverage-
                // period allocation): this line's OWN amount for each
                // group/period, same order as $calendarPeriods['periods']
                // — InvoiceIssuanceService stores this per-item, per-
                // period figure on each InstallmentCoveragePeriod row so
                // later payment allocations can be compared against the
                // correct "full settlement" amount for THAT specific
                // service/period, never a shared-installment aggregate.
                // Null for a non-calendar-billed line (nothing to persist).
                'period_amounts' => $groupAmounts,
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

        // Corrective pass #4 (HIGH 1 — calendar discount reconciliation,
        // confirmed real: a discounted invoice's installment schedule
        // used to still sum to the PRE-discount total, since the
        // discount was only ever applied at the aggregate subtotal
        // level, never allocated back down into each line's own amount
        // or its per-period breakdown). Every line's own FINAL
        // (post-discount) amount is computed here — proportional to its
        // own pre-discount share, bcmath throughout, the LAST eligible
        // line absorbing whatever rounding remainder is left (this
        // project's own established convention — the identical pattern
        // InstallmentPlanService::generate()'s percentage split and
        // generateCalendarSchedule()'s own per-group amounts already
        // use) — so SUM(line finals) === $total exactly, by
        // construction, never merely "usually correct."
        //
        // InvoiceItem.amount itself is deliberately left meaning exactly
        // what it already means everywhere else in this codebase (the
        // line's own PRE-discount charge — confirmed by reading
        // InvoiceIssuanceService::issue()'s existing, unmodified-by-this-pass
        // 'amount'=>$line['amount'] assignment, and every other existing
        // consumer of InvoiceItem.amount/remaining_amount, none of which
        // expect a discounted value there) — changing that meaning
        // globally would touch every discounted invoice in this
        // codebase, calendar-billed or not, far beyond this pass's own
        // scope and confirmed-real bug. The new, explicit, authoritative
        // value is $lines[$i]['metadata']['final_discounted_amount'] —
        // schedule/coverage-capacity reconciliation code must always
        // read THAT, never assume amount is already discounted.
        if ($calendarPeriods !== null) {
            $lines = $this->allocateDiscountAcrossLines($lines, $subtotal, $discount, $discountType, $discountValue);
        }

        // Corrective pass #4: $scheduleAmounts is rebuilt fresh here,
        // from each line's own FINAL (post-discount, already re-scaled
        // by allocateDiscountAcrossLines()) period_amounts — never from
        // the pre-discount figures computed during the loop above. This
        // is what InstallmentPlanService::generateCalendarSchedule()
        // actually bills per period, so it must reflect the discount.
        $scheduleAmounts = null;
        if ($calendarPeriods !== null) {
            $scheduleAmounts = array_fill(0, $calendarPeriods['count'], '0.00');
            foreach ($lines as $line) {
                if ($line['period_amounts'] === null) {
                    continue;
                }
                foreach ($line['period_amounts'] as $i => $groupAmount) {
                    $scheduleAmounts[$i] = bcadd($scheduleAmounts[$i], $groupAmount, 2);
                }

                $periodTotal = array_reduce($line['period_amounts'], fn ($carry, $amount) => bcadd($carry, $amount, 2), '0.00');
                if (bccomp($periodTotal, $line['amount'], 2) !== 0) {
                    throw new \App\Exceptions\Finance\DiscountReconciliationException('Calendar item period amounts do not reconcile to its final amount.');
                }
            }

            $lineTotal = array_reduce($lines, fn ($carry, $line) => bcadd($carry, $line['amount'], 2), '0.00');
            $scheduleTotal = array_reduce($scheduleAmounts, fn ($carry, $amount) => bcadd($carry, $amount, 2), '0.00');
            if (bccomp($lineTotal, $total, 2) !== 0 || bccomp($scheduleTotal, $total, 2) !== 0) {
                throw new \App\Exceptions\Finance\DiscountReconciliationException('Calendar lines and schedule do not reconcile to the invoice total.');
            }
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
            // Corrective pass #2 (P0 Blocker 1): the shared per-group
            // installment totals (summed across every line), consumed
            // verbatim by InstallmentPlanService::generateCalendarSchedule()
            // — null for every non-calendar-billed call. Corrective pass
            // #4: now always POST-discount.
            'schedule_amounts' => $scheduleAmounts,
        ];
    }

    /**
     * Corrective pass #4 (HIGH 1). Allocates $discount across $lines,
     * setting each line's metadata['final_discounted_amount'] and
     * re-scaling its own period_amounts (when present) to sum to that
     * same final amount — both using the "last eligible line/period
     * absorbs the rounding remainder" convention already established
     * elsewhere in this class/service (never divided evenly regardless
     * of line size, which would misrepresent which Fee actually absorbed
     * the discount).
     *
     * Percentage discount: each line's own share is intrinsically
     * proportional (amount x percent), so this is really just applying
     * the same percentage to every line independently — verified to sum
     * correctly via the remainder-absorption rule regardless.
     * Fixed discount: allocated by each line's own share of $subtotal
     * (amount / subtotal x discount) — never split evenly.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function allocateDiscountAcrossLines(array $lines, string $subtotal, string $discount, ?string $discountType, string|int|float|null $discountValue): array
    {
        if ($lines === []) {
            return $lines;
        }

        $percent = $discountType === 'percent' ? $this->money($discountValue ?? '0') : null;
        $lastIndex = array_key_last($lines);
        $allocatedDiscount = '0.00';

        foreach ($lines as $i => &$line) {
            $preDiscountAmount = $line['amount'];
            if ($i === $lastIndex) {
                $lineDiscount = bcsub($discount, $allocatedDiscount, 2);
            } elseif ($percent !== null) {
                $lineDiscount = bcdiv(bcmul($line['amount'], $percent, 6), '100.00', 2);
            } else {
                // Fixed discount, proportional by this line's own share
                // of the subtotal — never an even split.
                $lineDiscount = bccomp($subtotal, '0.00', 2) === 0
                    ? '0.00'
                    : bcdiv(bcmul($line['amount'], $discount, 6), $subtotal, 2);
            }
            $allocatedDiscount = bcadd($allocatedDiscount, $lineDiscount, 2);

            $final = bcsub($line['amount'], $lineDiscount, 2);
            if (bccomp($final, '0.00', 2) < 0) {
                throw ValidationException::withMessages([
                    'discount_value' => "Скидка приводит к отрицательной сумме по услуге «{$line['description']}» — проверьте параметры скидки.",
                ]);
            }

            $line['metadata']['final_discounted_amount'] = $final;
            $line['metadata']['pre_discount_amount'] = $preDiscountAmount;
            $line['metadata']['allocated_discount_amount'] = $lineDiscount;
            $line['metadata']['line_total'] = $final;
            if ($line['period_amounts'] !== null) {
                $line['period_amounts'] = $this->rescalePeriodAmounts($line['period_amounts'], $final);
            }
            $line['amount'] = $final;

            // Discounted calendar lines and mixed quarterly package/tail
            // lines are composite financial charges. Persist a truthful
            // identity representation instead of a rounded pseudo-rate.
            if (bccomp($lineDiscount, '0.00', 2) !== 0 || ($line['metadata']['quarterly_package_applied'] ?? false)) {
                $line['metadata']['display_unit_price'] = $line['unit_price'];
                $line['metadata']['display_quantity'] = (string) $line['quantity'];
                $line['unit_price'] = $final;
                $line['quantity'] = 1;
            }
        }
        unset($line);

        return $lines;
    }

    /**
     * Corrective pass #4 (HIGH 1). Re-scales a line's own per-period
     * amounts (originally summing to its pre-discount amount) to instead
     * sum to exactly $targetTotal (its final, post-discount amount) —
     * same proportional-share-with-last-absorbing-remainder rule as
     * allocateDiscountAcrossLines() itself, applied one level deeper.
     *
     * @param  array<int, string>  $periodAmounts
     * @return array<int, string>
     */
    private function rescalePeriodAmounts(array $periodAmounts, string $targetTotal): array
    {
        $originalTotal = array_reduce($periodAmounts, fn ($carry, $amount) => bcadd($carry, $amount, 2), '0.00');
        if (bccomp($originalTotal, '0.00', 2) === 0) {
            // A genuinely zero-amount period breakdown (should not occur
            // for a real charged line) — nothing to proportionally
            // re-scale against; left as-is rather than dividing by zero.
            return $periodAmounts;
        }

        $lastIndex = array_key_last($periodAmounts);
        $allocated = '0.00';
        $rescaled = [];
        foreach ($periodAmounts as $i => $amount) {
            if ($i === $lastIndex) {
                $rescaled[$i] = bcsub($targetTotal, $allocated, 2);
            } else {
                $share = bcdiv(bcmul($amount, $targetTotal, 6), $originalTotal, 2);
                $rescaled[$i] = $share;
                $allocated = bcadd($allocated, $share, 2);
            }
        }

        return $rescaled;
    }

    /**
     * Corrective pass #2 (P0 Blocker 1 — partial final quarter). Prices a
     * single quarterly-billed line across CalendarPeriodCalculator's own
     * per-group ('months') breakdown:
     *
     *  - DERIVED quarterly (no explicit quarterly FeePrice; resolvePrice()
     *    already derived monthly x 3): every group — full 3-month or a
     *    trailing 1-2 month partial — is simply monthly_unit x that
     *    group's own month-count. The line collapses to a MONTHLY-unit
     *    representation (unit_price=monthly rate, quantity=total months
     *    covered), since that is the one uniform rate the whole span
     *    actually uses — algebraically identical to (monthly x 3) applied
     *    per full quarter plus (monthly x remainder) for the partial one.
     *
     *  - EXPLICIT quarterly package price (a real quarterly FeePrice
     *    resolved): every FULL 3-month group uses that package price
     *    as-is. A trailing partial group (1-2 months) never gets a
     *    prorated slice of the package price — it uses a SEPARATELY
     *    resolved monthly basis tariff x its own month-count instead
     *    (same "never derive by dividing the package price" rule already
     *    established for yearly). If a partial group exists and no
     *    monthly basis is resolvable, the entire issuance fails loudly.
     *
     * @param  array<string, mixed>  $item  The raw submitted line selection (same shape resolvePrice() receives).
     * @param  array<string, mixed>  $resolvedPrice  resolvePrice()'s own return value for this line.
     * @param  string  $baseAmount  resolvedPrice()'s amount, after first_last_month doubling (if any) — the per-unit tariff (quarterly package price, or the already-doubled derived amount).
     * @param  array{count:int, periods: array<int, array{start:string,end:string,months:int}>}  $calendarPeriods
     * @return array{unit_price:string, quantity:int, amount:string, group_amounts: array<int,string>, metadata: array<string,mixed>}
     */
    private function priceQuarterlyLine(Fee $fee, array $item, array $resolvedPrice, string $baseAmount, array $calendarPeriods, string $pricingDate, ?int $academicYearId): array
    {
        $groups = $calendarPeriods['periods'];
        $isDerived = ($resolvedPrice['metadata']['derived'] ?? false) === true;

        if ($isDerived) {
            $monthlyUnit = $resolvedPrice['metadata']['monthly_unit_amount'];
            $groupAmounts = [];
            $totalMonths = 0;
            foreach ($groups as $group) {
                $totalMonths += $group['months'];
                $groupAmounts[] = bcmul($monthlyUnit, (string) $group['months'], 2);
            }
            $amount = array_reduce($groupAmounts, fn ($carry, $g) => bcadd($carry, $g, 2), '0.00');

            return [
                'unit_price' => $monthlyUnit,
                'quantity' => $totalMonths,
                'amount' => $amount,
                'group_amounts' => $groupAmounts,
                'metadata' => [],
            ];
        }

        $hasPartialGroup = collect($groups)->contains(fn ($g) => $g['months'] < 3);
        $monthlyBasis = null;
        if ($hasPartialGroup) {
            $basisPrice = $this->resolveCoverageBasisPrice($fee, $item, $pricingDate, $academicYearId, 'monthly');
            if (! $basisPrice) {
                throw ValidationException::withMessages([
                    'fees' => "Для услуги «{$fee->name_ru}» отсутствует базовый месячный тариф для неполного квартала — настройте его перед оформлением.",
                ]);
            }
            $monthlyBasis = $this->money($basisPrice->getRawOriginal('amount'));
        }

        $groupAmounts = [];
        $fullGroupCount = 0;
        $totalMonths = 0;
        foreach ($groups as $group) {
            $totalMonths += $group['months'];
            if ($group['months'] === 3) {
                $groupAmounts[] = $baseAmount;
                $fullGroupCount++;
            } else {
                $groupAmounts[] = bcmul($monthlyBasis, (string) $group['months'], 2);
            }
        }
        $amount = array_reduce($groupAmounts, fn ($carry, $g) => bcadd($carry, $g, 2), '0.00');

        if (! $hasPartialGroup) {
            // An exact multiple of 3 months — every group is a full
            // package block, unit_price x quantity already equals amount
            // precisely, no blending needed.
            return [
                'unit_price' => $baseAmount,
                'quantity' => $fullGroupCount,
                'amount' => $amount,
                'group_amounts' => $groupAmounts,
                'metadata' => [],
            ];
        }

        $lastGroup = $groups[array_key_last($groups)];
        $partialMetadata = [
            'partial_group_months' => (string) $lastGroup['months'],
            'partial_group_unit_price' => $monthlyBasis,
            'partial_group_amount' => end($groupAmounts),
            'partial_group_start' => $lastGroup['start'],
            'partial_group_end' => $lastGroup['end'],
        ];

        if ($fullGroupCount === 0) {
            // Corrective pass #3 (HIGH 3 — zero complete quarterly
            // blocks): the ENTIRE span is a partial group (e.g. a
            // 1-2 month remainder under quarterly billing) — there is
            // no package block to represent at all. The line is
            // represented EXACTLY as its real tariff: unit_price = the
            // monthly basis amount, quantity = the remaining months,
            // amount = their product — never quantity=0 for a non-zero
            // amount, and never a fabricated "0 quarters" line.
            return [
                'unit_price' => $monthlyBasis,
                'quantity' => $totalMonths,
                'amount' => $amount,
                'group_amounts' => $groupAmounts,
                'metadata' => array_merge($partialMetadata, [
                    'requested_billing_strategy' => 'quarterly',
                    'complete_quarterly_blocks' => '0',
                    'quarterly_package_applied' => false,
                ]),
            ];
        }

        // Corrective pass #3 (HIGH 3 — mixed full blocks + trailing
        // partial): a literal unit_price=package/quantity=fullGroupCount
        // cannot truthfully multiply out to $amount once a partial group
        // is blended in (e.g. 3x2800 + 1x1000 = 9400, but 2800x3=8400
        // != 9400) — checked directly against this codebase's own
        // InvoiceItem shape: one row per Fee per invoice, no existing
        // multi-item-per-Fee split for this case (only the SEPARATE,
        // pre-existing $hasDuplicateFeeLines path, which requires the
        // CALLER to submit two distinct line entries — not something
        // this single-line quarterly computation can synthesize without
        // restructuring calculate()'s own per-item loop). Per the
        // corrective-pass instruction's own explicit default: a derived
        // BLENDED unit_price = amount / total_months_covered keeps
        // unit_price x quantity == amount arithmetically true (quantity
        // = total months), while the full breakdown (package price,
        // full-block count, partial months/basis, and every group's own
        // real amount) is fully recorded in metadata — the audit truth
        // lives there, never hidden. Note: for the rare amount not
        // evenly divisible by total_months, bcdiv's 2-decimal rounding
        // could in principle leave unit_price x quantity a single cent
        // off from $amount — $amount itself (and $group_amounts, which
        // drive the actual installment schedule) always remain the
        // authoritative, exact figures regardless.
        return [
            // One composite charge is the only truthful scalar
            // representation when package blocks and a monthly tail use
            // different tariffs. Exact components remain below.
            'unit_price' => $amount,
            'quantity' => 1,
            'amount' => $amount,
            'group_amounts' => $groupAmounts,
            'metadata' => array_merge($partialMetadata, [
                'requested_billing_strategy' => 'quarterly',
                'quarterly_package_price' => $baseAmount,
                'complete_quarterly_blocks' => (string) $fullGroupCount,
                'quarterly_package_applied' => true,
                'blended_unit_price' => null,
                'component_month_count' => (string) $totalMonths,
                'per_block_amounts' => $groupAmounts,
            ]),
        ];
    }

    /**
     * Finance V2, Phase 2D corrective pass (P0 Blocker 2 / yearly & Food
     * adjustment basis). ServiceCoverage only ever accepts a FeePrice
     * whose payment_period is 'monthly' or 'daily' (ServiceCoverageService::
     * sourceTariff()'s own hard constraint, unchanged) — so a Fee billed
     * quarterly/yearly (basis 'monthly' needed) or a Food Fee charged at a
     * monthly/quarterly/yearly rate but tracked at daily coverage
     * granularity (basis 'daily' needed) cannot use its own resolved,
     * differently-denominated FeePrice as the coverage's tariff. This
     * resolves a SEPARATE $targetPeriod tariff for the exact same
     * Fee/dimensions, valid as of the same pricing date, using the
     * identical dimensional matching + effective-date rules every other
     * price lookup in this class uses (same dimensionalCandidates()/
     * selectAmongCandidates() pair the quarterly-derivation logic already
     * reuses) — never a division/conversion of the charged price, never
     * invented.
     *
     * Corrective pass #2 (HIGH 5 — complete, shared canonical tariff
     * dimension set): $selection must be the FULL selection/metadata
     * array (the same shape resolvePrice() itself receives — grade_id,
     * grade_group, payment_period, option_type, option_value, size, item,
     * enrollment_mode_id — whatever the invoiced line actually carries),
     * never a hand-picked subset. dimensionalCandidates() already knows
     * how to use every one of those fields; passing a curated subset here
     * (as this method used to require of its callers) silently dropped
     * grade_id/enrollment_mode_id matching for basis-price resolution
     * even though primary price resolution uses them — this is now the
     * SAME matching call, on the SAME input shape, for both.
     *
     * @param  array<string, mixed>  $selection  The full selection/metadata array — see above.
     */
    public function resolveCoverageBasisPrice(Fee $fee, array $selection, string $date, ?int $academicYearId, string $targetPeriod): ?FeePrice
    {
        $selection['payment_period'] = $targetPeriod;
        $cache = [];
        $candidates = $this->dimensionalCandidates($fee, $selection, $academicYearId, $cache);

        return $this->selectAmongCandidates($candidates, $date);
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
                && $this->isUsable($price, $date)
                && $this->explicitPriceMatchesSelection($price, $selection, $modeCache);

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
            $modeValues = collect([$mode?->code, $mode?->name_ru, $mode?->short_name_ru])->filter()->unique()->values();
            $hasModePrices = (clone $query)->whereIn('option_type', self::MODE_OPTION_TYPES)->exists();
            if ($hasModePrices) {
                $query->whereIn('option_type', self::MODE_OPTION_TYPES)->whereIn('option_value', $modeValues);
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
     * Finance V2, Phase 2D corrective pass #3 (P0 Blocker 2 — complete
     * canonical tariff-dimension validation, everywhere). Validates an
     * EXPLICITLY chosen FeePrice (the fee_price_id selection path)
     * against the full canonical dimension set — grade_id (via
     * DIMENSION_FIELDS; previously absent from this branch entirely, a
     * confirmed real gap: a client submitting a wrong grade's explicit
     * fee_price_id passed as long as grade_group happened to match or
     * was blank), grade_group, payment_period, size, item, and option_type/
     * option_value (including the enrollment_mode_id fallback — the
     * SAME MODE_OPTION_TYPES constant dimensionalCandidates() itself
     * uses, so the two can never independently disagree about which
     * option_type values mean "this tariff is mode-scoped").
     *
     * This is now the SINGLE dimension-matching implementation for the
     * explicit-id path — resolvePrice()'s only caller of this method —
     * never a second, hand-built subset foreach list that a future
     * change could update here without updating dimensionalCandidates(),
     * or vice versa.
     */
    private function explicitPriceMatchesSelection(FeePrice $price, array $selection, array &$modeCache): bool
    {
        // Grade: mirrors dimensionalCandidates()'s own two-tier semantics
        // (a grade_group-scoped price matches the selection's own
        // grade_group, direct or derived from grade_id; a grade_id-scoped
        // price must match the selection's own grade_id exactly — no
        // grade_group fallback for an EXPLICIT row, which already
        // committed to one specific grade_id), but applies this
        // codebase's existing STRICT rule for every other dimension here:
        // if the price itself carries a value for a dimension, the
        // selection must explicitly supply the matching value — a blank
        // selection field never silently satisfies a filled price field.
        if (filled($price->grade_id)) {
            if ((int) ($selection['grade_id'] ?? 0) !== (int) $price->grade_id) {
                return false;
            }
        } elseif (filled($price->grade_group)) {
            $selectionGroup = $selection['grade_group'] ?? $this->gradeGroupFor($selection['grade_id'] ?? null);
            if ((string) $price->grade_group !== (string) $selectionGroup) {
                return false;
            }
        }

        foreach (['payment_period', 'size', 'item'] as $field) {
            if (filled($price->{$field}) && (string) ($selection[$field] ?? '') !== (string) $price->{$field}) {
                return false;
            }
        }

        if (filled($price->option_value)) {
            // A mode-scoped price whose selection didn't send option_type/
            // option_value explicitly is validated via enrollment_mode_id
            // instead — the identical fallback dimensionalCandidates()
            // applies for automatic resolution, now applied here too so
            // an explicit fee_price_id selection for a mode-scoped tariff
            // isn't spuriously rejected just for omitting a redundant
            // option_type/option_value the caller was never asked to send.
            if (blank($selection['option_type'] ?? null) && blank($selection['option_value'] ?? null)
                && in_array($price->option_type, self::MODE_OPTION_TYPES, true)) {
                $modeId = (int) ($selection['enrollment_mode_id'] ?? 0);
                if ($modeId <= 0) {
                    return false;
                }
                $mode = array_key_exists($modeId, $modeCache) ? $modeCache[$modeId] : ($modeCache[$modeId] = EnrollmentMode::find($modeId));
                $modeValues = collect([$mode?->code, $mode?->name_ru, $mode?->short_name_ru])->filter()->map(fn ($v) => (string) $v);
                if (! $modeValues->contains((string) $price->option_value)) {
                    return false;
                }
            } elseif ((string) ($selection['option_value'] ?? '') !== (string) $price->option_value
                || (filled($price->option_type) && (string) ($selection['option_type'] ?? '') !== (string) $price->option_type)) {
                return false;
            }
        } elseif (filled($price->option_type) && (string) ($selection['option_type'] ?? '') !== (string) $price->option_type) {
            return false;
        }

        return true;
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
