<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\PaymentPlan;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class InstallmentPlanService
{
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
     * (docs/finance-v2-architecture.md).
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
     * 'yearly' delegates to generateSingle() — a single full-amount
     * installment is exactly what a yearly schedule is, so it is not
     * duplicated as a second code path.
     *
     * First installment is due immediately (on $startDate itself — the
     * starting period's charge is due now, not on a future calendar
     * boundary). Every subsequent installment is due on the 1st of its
     * own calendar month/quarter — a real calendar boundary, never
     * $startDate + N days.
     *
     * Rounding: total ÷ period-count, each period bcmath-truncated to 2dp,
     * with the LAST period absorbing whatever remainder is left over — the
     * same last-segment rounding-safety pattern generate() already uses
     * for percentage splits, applied here to a straight equal split.
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
     * @return array<int, array{installment: InvoiceInstallment, period_start: string, period_end: string}>
     */
    public function generateCalendarSchedule(Invoice $invoice, string $billingPeriod, string $startDate, string $academicYearEndDate): array
    {
        if ($billingPeriod === 'yearly') {
            $this->generateSingle($invoice, $startDate);

            return [[
                'installment' => $invoice->installments()->sole(),
                'period_start' => Carbon::parse($startDate)->toDateString(),
                'period_end' => Carbon::parse($academicYearEndDate)->toDateString(),
            ]];
        }

        if (! in_array($billingPeriod, ['monthly', 'quarterly'], true)) {
            throw ValidationException::withMessages(['billing_period' => 'Неизвестный период оплаты.']);
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $yearEnd = Carbon::parse($academicYearEndDate)->startOfDay();
        if ($yearEnd->lt($start)) {
            throw ValidationException::withMessages(['registration_date' => 'Дата регистрации не может быть позже окончания учебного года.']);
        }

        $monthsPerPeriod = $billingPeriod === 'quarterly' ? 3 : 1;

        // Calendar-quarter boundaries (Jan/Apr/Jul/Oct), never an
        // arbitrary 3-month window starting at the registration date —
        // "calendar periods, not day/month offsets" applies to quarter
        // alignment too.
        $periodStart = $start->copy()->startOfMonth();
        if ($billingPeriod === 'quarterly') {
            $quarterStartMonth = (intdiv($periodStart->month - 1, 3) * 3) + 1;
            $periodStart->month($quarterStartMonth);
        }

        $periodEndAnchor = $yearEnd->copy()->startOfMonth();
        if ($billingPeriod === 'quarterly') {
            $quarterStartMonth = (intdiv($periodEndAnchor->month - 1, 3) * 3) + 1;
            $periodEndAnchor->month($quarterStartMonth);
        }

        $periodCount = $periodStart->diffInMonths($periodEndAnchor) / $monthsPerPeriod + 1;
        $periodCount = (int) round($periodCount);

        $total = bcadd((string) $invoice->total_amount, '0', 2);
        $each = bcdiv($total, (string) $periodCount, 2);
        $allocated = '0.00';
        $created = [];

        for ($i = 0; $i < $periodCount; $i++) {
            $last = $i === $periodCount - 1;
            $amount = $last ? bcsub($total, $allocated, 2) : $each;
            $allocated = bcadd($allocated, $amount, 2);

            // The calendar period this installment represents — always
            // the real month/quarter boundary, even for the first period
            // (whose due_date, computed separately below, is deliberately
            // the registration date instead — due date and coverage period
            // are two different facts, never derived from one another).
            $periodBoundaryStart = $periodStart->copy()->addMonths($i * $monthsPerPeriod);
            $periodBoundaryEnd = $periodBoundaryStart->copy()->addMonths($monthsPerPeriod)->subDay();

            $dueDate = $i === 0
                ? $start->copy()
                : $periodBoundaryStart->copy();

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
                'period_start' => $periodBoundaryStart->toDateString(),
                'period_end' => $periodBoundaryEnd->toDateString(),
            ];
        }

        return $created;
    }
}
