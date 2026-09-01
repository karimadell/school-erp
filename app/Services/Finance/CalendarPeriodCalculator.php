<?php

namespace App\Services\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Finance V2, Phase 2D corrective pass (P0 Blocker 1, pass #1) and
 * corrective pass #2 (P0 Blocker 1 fix — service-anchored quarters).
 *
 * The single, shared, PURE calendar-period boundary computation for
 * monthly/quarterly/yearly billing — real calendar-month boundaries
 * anchored to the ACTUAL service start month, never a fixed day-offset and
 * never snapped to civil (January-anchored) quarters. No proration: the
 * starting period is always a full period regardless of how far into it
 * $startDate falls, and the closing group of a quarterly schedule is never
 * padded to a full quarter or extended past the real service/academic-year
 * end — it simply takes whatever 1-2 months remain.
 *
 * Extracted from InstallmentPlanService::generateCalendarSchedule() (which
 * used to compute this boundary math inline) so BOTH pricing
 * (InvoiceCalculationService — how many units, and each group's own
 * amount) and scheduling (InstallmentPlanService — how many installments,
 * their due dates, and each installment's exact amount) call the exact
 * same period-counting logic and can never disagree about how many
 * periods exist or where they fall.
 *
 * No DB access, no writes, no pricing knowledge — pure date arithmetic
 * only. Each returned period carries 'months' (how many calendar months
 * that group spans — always 1 for monthly, 3 for a full quarterly group,
 * 1-2 for a trailing partial quarterly group) so a caller can price each
 * group by its own month-count without this class knowing anything about
 * tariffs.
 */
class CalendarPeriodCalculator
{
    /**
     * @return array{count: int, periods: array<int, array{start: string, end: string, months: int}>}
     */
    public function resolve(string $billingPeriod, string $startDate, string $academicYearEndDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $yearEnd = Carbon::parse($academicYearEndDate)->startOfDay();
        if ($yearEnd->lt($start)) {
            throw ValidationException::withMessages(['registration_date' => 'Дата регистрации не может быть позже окончания учебного года.']);
        }

        if ($billingPeriod === 'yearly') {
            // Coverage/pricing periods snap to full calendar-month
            // boundaries the same way monthly/quarterly do below — a
            // mid-month registration must not produce a coverage_start
            // that fails the "full calendar month" rule
            // ServiceCoverageService::record()/recordWithBasisPrice()
            // enforce for billing_unit='monthly'. The installment's own
            // due_date is a separate concept and stays the raw
            // registration date (see InstallmentPlanService::
            // generateCalendarSchedule()'s own yearly branch) — this only
            // snaps the coverage/pricing PERIOD start, never the due date.
            $periodStart = $start->copy()->startOfMonth();

            return [
                'count' => 1,
                'periods' => [[
                    'start' => $periodStart->toDateString(),
                    'end' => $yearEnd->toDateString(),
                    'months' => $periodStart->diffInMonths($yearEnd->copy()->startOfMonth()) + 1,
                ]],
            ];
        }

        if (! in_array($billingPeriod, ['monthly', 'quarterly'], true)) {
            throw ValidationException::withMessages(['billing_period' => 'Неизвестный период оплаты.']);
        }

        // Corrective pass #2 (P0 Blocker 1): quarters are consecutive
        // 3-month chunks anchored to the ACTUAL service start month — a
        // September-start service groups Sep-Nov / Dec-Feb / Mar-May,
        // never civil (January-anchored) quarters Jul-Sep / Oct-Dec /
        // etc, which would bill for months before the student even
        // enrolled. Monthly reuses the identical loop with a 1-month
        // group size, so the two billing periods can never structurally
        // disagree about where periods start.
        $monthsPerGroup = $billingPeriod === 'quarterly' ? 3 : 1;
        $periodStart = $start->copy()->startOfMonth();
        $periodEndAnchor = $yearEnd->copy()->startOfMonth();
        $totalMonths = $periodStart->diffInMonths($periodEndAnchor) + 1;

        $periods = [];
        $cursor = $periodStart->copy();
        $monthsRemaining = $totalMonths;
        while ($monthsRemaining > 0) {
            // The LAST group takes whatever remains (1 or 2 months for
            // quarterly) rather than padding to a full quarter or
            // extending coverage past the real service/academic-year end
            // — a genuinely different-sized, correctly-priced group, not
            // an even division.
            $groupMonths = min($monthsPerGroup, $monthsRemaining);
            $groupStart = $cursor->copy();
            // Capped at the real academic-year end even when it falls
            // mid-month (a non-month-aligned end date) — coverage/pricing
            // periods must never extend past the actual service/academic
            // year boundary; the group's own 'months' count (used for
            // pricing) is left uncapped, since the existing "no
            // proration" policy already means a period that legitimately
            // starts mid-month is still billed as a full unit — the same
            // symmetry applies to a period that legitimately ends
            // mid-month at the true academic-year boundary.
            $groupEnd = $groupStart->copy()->addMonths($groupMonths)->subDay()->min($yearEnd);
            $periods[] = ['start' => $groupStart->toDateString(), 'end' => $groupEnd->toDateString(), 'months' => $groupMonths];
            $cursor->addMonths($groupMonths);
            $monthsRemaining -= $groupMonths;
        }

        return ['count' => count($periods), 'periods' => $periods];
    }
}
