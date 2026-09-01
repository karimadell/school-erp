<?php

namespace App\Services\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Finance V2, Phase 2D corrective pass (P0 Blocker 1).
 *
 * The single, shared, PURE calendar-period boundary computation for
 * monthly/quarterly/yearly billing — real calendar-month/quarter
 * boundaries, never a fixed day-offset. No proration: the starting period
 * is always a full period regardless of how far into it $startDate falls.
 *
 * Extracted from InstallmentPlanService::generateCalendarSchedule() (which
 * used to compute this boundary math inline) so BOTH pricing
 * (InvoiceCalculationService — how many units to multiply the unit price
 * by) and scheduling (InstallmentPlanService — how many installments, and
 * their due dates) call the exact same period-counting logic and can never
 * disagree about how many periods a given registration date/billing
 * period/academic-year-end combination produces.
 *
 * No DB access, no writes — pure date arithmetic only.
 */
class CalendarPeriodCalculator
{
    /**
     * @return array{count: int, periods: array<int, array{start: string, end: string}>}
     */
    public function resolve(string $billingPeriod, string $startDate, string $academicYearEndDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $yearEnd = Carbon::parse($academicYearEndDate)->startOfDay();
        if ($yearEnd->lt($start)) {
            throw ValidationException::withMessages(['registration_date' => 'Дата регистрации не может быть позже окончания учебного года.']);
        }

        if ($billingPeriod === 'yearly') {
            // Corrective pass follow-up: coverage/pricing periods snap to
            // full calendar-month boundaries the same way monthly/quarterly
            // already do below — a mid-month registration must not produce
            // a coverage_start that fails the "full calendar month" rule
            // ServiceCoverageService::record()/recordWithBasisPrice()
            // enforce for billing_unit='monthly'. The installment's own
            // due_date is a separate concept and stays the raw
            // registration date (see InstallmentPlanService::
            // generateCalendarSchedule()'s own yearly branch) — this only
            // snaps the coverage/pricing PERIOD start, never the due date.
            return [
                'count' => 1,
                'periods' => [['start' => $start->copy()->startOfMonth()->toDateString(), 'end' => $yearEnd->toDateString()]],
            ];
        }

        if (! in_array($billingPeriod, ['monthly', 'quarterly'], true)) {
            throw ValidationException::withMessages(['billing_period' => 'Неизвестный период оплаты.']);
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

        $count = $periodStart->diffInMonths($periodEndAnchor) / $monthsPerPeriod + 1;
        $count = (int) round($count);

        $periods = [];
        for ($i = 0; $i < $count; $i++) {
            $periodBoundaryStart = $periodStart->copy()->addMonths($i * $monthsPerPeriod);
            $periodBoundaryEnd = $periodBoundaryStart->copy()->addMonths($monthsPerPeriod)->subDay();
            $periods[] = ['start' => $periodBoundaryStart->toDateString(), 'end' => $periodBoundaryEnd->toDateString()];
        }

        return ['count' => $count, 'periods' => $periods];
    }
}
