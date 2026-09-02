<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\PaymentPlan;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class InstallmentPlanService
{
    public function __construct(private CalendarPeriodCalculator $periods)
    {
    }

    public function generate(Invoice $invoice, PaymentPlan $plan, string $baseDate, ?array $manualAmounts = null): void
    {
        $segments = $plan->installments()->get();
        if ($segments->isEmpty()) throw ValidationException::withMessages(['payment_plan_id'=>'В выбранном плане отсутствуют этапы оплаты.']);
        $total = bcadd((string)$invoice->total_amount, '0', 2);
        $allocated = '0.00';
        foreach ($segments as $index => $segment) {
            $last = $index === $segments->count() - 1;
            $amount = isset($manualAmounts[$index]) ? bcadd((string)$manualAmounts[$index], '0', 2)
                : ($last ? bcsub($total,$allocated,2) : bcdiv(bcmul($total,(string)$segment->percentage,4),'100',2));
            if (bccomp($amount,'0.00',2) <= 0) throw ValidationException::withMessages(['installments'=>'Сумма каждого этапа должна быть больше нуля.']);
            $allocated = bcadd($allocated,$amount,2);
            InvoiceInstallment::create(['invoice_id'=>$invoice->id,'payment_plan_id'=>$plan->id,'name_ru'=>$segment->name_ru,
                'sequence'=>$segment->sequence,'due_date'=>Carbon::parse($baseDate)->addDays($segment->offset_days),
                'amount'=>$amount,'paid_amount'=>'0.00','remaining_amount'=>$amount,'status'=>'pending']);
        }
        if (bccomp($allocated,$total,2)!==0) throw ValidationException::withMessages(['installments'=>'Сумма этапов должна совпадать с итогом счёта.']);
    }

    public function generateSingle(Invoice $invoice, string $dueDate): void
    {
        InvoiceInstallment::create(['invoice_id'=>$invoice->id,'name_ru'=>'Полная оплата','sequence'=>1,'due_date'=>$dueDate,
            'amount'=>$invoice->total_amount,'paid_amount'=>'0.00','remaining_amount'=>$invoice->total_amount,'status'=>'pending']);
    }

    /**
     * Finance V2, Phase 2B — service-aware billing schedules
     * (docs/finance-v2-architecture.md). Corrected in Phase 2D's corrective
     * pass (P0 Blocker 1): this method distributes an invoice TOTAL across
     * installments — it is the caller's responsibility (InvoiceCalculationService,
     * via InvoiceIssuanceService) to ensure that total already correctly
     * reflects unit price x covered period count BEFORE calling this. This
     * method itself no longer computes or assumes anything about pricing.
     *
     * Calendar-period installment generation: monthly/quarterly/yearly,
     * spaced by real calendar-month/quarter boundaries — never a fixed
     * day-offset (that remains InstallmentPlanService::generate()'s job,
     * for explicit custom PaymentPlans only). No proration: the starting
     * period is always charged in full regardless of how far into it
     * $startDate falls — any partial-period discount is a separate,
     * explicit administrator adjustment (finance.adjustments), never
     * automatic here.
     *
     * Period boundaries (count, start/end per period) are computed by the
     * shared CalendarPeriodCalculator — the SAME instance/logic
     * InvoiceCalculationService uses to compute the unit-count that scaled
     * this invoice's total in the first place, so pricing and scheduling
     * can never disagree about how many periods exist.
     *
     * First installment is due immediately (on $startDate itself — the
     * starting period's charge is due now, not on a future calendar
     * boundary). Every subsequent installment is due on the 1st of its
     * own calendar month/quarter — a real calendar boundary, never
     * $startDate + N days.
     *
     * Rounding: when the caller does not supply $scheduleAmounts, total ÷
     * period-count, each period bcmath-truncated to 2dp, with the LAST
     * period absorbing whatever remainder is left over — the same
     * last-segment rounding-safety pattern generate() already uses for
     * percentage splits, applied here to a straight equal split. This is
     * only correct for a UNIFORM per-period price (monthly, or a
     * quarterly span with no partial trailing group) — see
     * $scheduleAmounts below for the general case.
     *
     * Corrective pass #2 (P0 Blocker 1 — partial final quarter): a
     * quarterly span whose covered months aren't an exact multiple of 3
     * has a trailing partial group (1-2 months) priced DIFFERENTLY from
     * the full 3-month groups (see InvoiceCalculationService's per-group
     * pricing) — an even division of the invoice total across N
     * installments would be WRONG the moment groups differ in size.
     * $scheduleAmounts, when provided, is the caller's own per-group
     * amount breakdown (same order/count as CalendarPeriodCalculator's
     * own periods array — the identical shared calculator both this
     * method and InvoiceCalculationService call), used VERBATIM per
     * installment instead of any division — pricing and scheduling can
     * therefore never disagree, by construction. Null (the default)
     * preserves the even-split fallback for every caller that has no
     * per-group pricing context of its own (e.g. a directly-constructed
     * test invoice, or any future non-priced caller).
     *
     * Finance V2, Phase 2D: returns the exact {installment, period_start,
     * period_end} tuple for every installment created — the CALENDAR
     * period each installment represents, deliberately independent of its
     * due_date (the first period's due_date is the registration date
     * itself — no proration — but its period_start is always that
     * calendar month/quarter's real 1st). The caller (InvoiceIssuanceService)
     * uses this to create ServiceCoverage + installment_coverage_periods
     * rows; this method itself has no knowledge of either — it is still
     * purely about the invoice TOTAL and its installment amounts, never
     * about individual Fees/items.
     *
     * @param  ?array<int, string>  $scheduleAmounts  See above.
     * @return array<int, array{installment: InvoiceInstallment, period_start: string, period_end: string}>
     */
    public function generateCalendarSchedule(Invoice $invoice, string $billingPeriod, string $startDate, string $academicYearEndDate, ?array $scheduleAmounts = null): array
    {
        if ($billingPeriod === 'yearly') {
            $this->generateSingle($invoice, $startDate);

            // period_start is month-snapped (matching CalendarPeriodCalculator's
            // own yearly branch, kept in agreement here since this method
            // doesn't call it for the yearly shortcut) — due_date above
            // deliberately stays the raw, un-snapped registration date.
            return [[
                'installment' => $invoice->installments()->sole(),
                'period_start' => Carbon::parse($startDate)->startOfMonth()->toDateString(),
                'period_end' => Carbon::parse($academicYearEndDate)->toDateString(),
            ]];
        }

        $resolved = $this->periods->resolve($billingPeriod, $startDate, $academicYearEndDate);
        $periodCount = $resolved['count'];
        $start = Carbon::parse($startDate)->startOfDay();

        if ($scheduleAmounts !== null && count($scheduleAmounts) !== $periodCount) {
            throw ValidationException::withMessages(['services' => 'Количество сумм по периодам не совпадает с количеством периодов.']);
        }

        $total = bcadd((string) $invoice->total_amount, '0', 2);
        if ($scheduleAmounts !== null) {
            $scheduleTotal = array_reduce($scheduleAmounts, fn ($carry, $amount) => bcadd($carry, (string) $amount, 2), '0.00');
            if (bccomp($scheduleTotal, $total, 2) !== 0) {
                throw ValidationException::withMessages(['services' => 'Сумма календарного графика не совпадает с итоговой суммой счёта.']);
            }
        }
        $each = bcdiv($total, (string) $periodCount, 2);
        $allocated = '0.00';
        $created = [];

        foreach ($resolved['periods'] as $i => $period) {
            if ($scheduleAmounts !== null) {
                $amount = $scheduleAmounts[$i];
            } else {
                $last = $i === $periodCount - 1;
                $amount = $last ? bcsub($total, $allocated, 2) : $each;
                $allocated = bcadd($allocated, $amount, 2);
            }

            $dueDate = $i === 0 ? $start->copy() : Carbon::parse($period['start']);

            $installment = InvoiceInstallment::create([
                'invoice_id' => $invoice->id,
                'name_ru' => 'Период '.($i + 1),
                'sequence' => $i + 1,
                'due_date' => $dueDate,
                'amount' => $amount,
                'paid_amount' => '0.00',
                'remaining_amount' => $amount,
                'status' => 'pending',
            ]);

            $created[] = [
                'installment' => $installment,
                'period_start' => $period['start'],
                'period_end' => $period['end'],
            ];
        }

        return $created;
    }
}
