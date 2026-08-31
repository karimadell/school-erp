<?php

namespace Tests\Feature\Finance;

use App\Services\Finance\InstallmentPlanService;

/**
 * Finance V2, Phase 2B (docs/finance-v2-architecture.md) — direct,
 * service-level coverage of InstallmentPlanService::generateCalendarSchedule(),
 * the new calendar-period (monthly/quarterly/yearly) generator. Complements
 * QuickRegistrationBillingSchedulesTest, which covers the HTTP-level
 * service-aware allowed-period wiring; this file locks in the arithmetic,
 * calendar-boundary, and rounding behaviour of the generator itself in
 * isolation, against directly-constructed Invoice fixtures (bypassing
 * InvoiceIssuanceService entirely — the same pattern FinanceOperationsTestCase's
 * own invoice() helper already uses elsewhere in this suite).
 */
class InstallmentPlanServiceCalendarTest extends FinanceOperationsTestCase
{
    private function service(): InstallmentPlanService
    {
        return app(InstallmentPlanService::class);
    }

    // ----- monthly -----------------------------------------------------------

    public function test_monthly_schedule_produces_one_installment_per_calendar_month_through_year_end(): void
    {
        $invoice = $this->invoice('1000.00');
        // Academic year end_date is 2027-06-30 (FinanceOperationsTestCase).
        $this->service()->generateCalendarSchedule($invoice, 'monthly', '2026-08-15', $this->year->end_date->toDateString());

        // August 2026 through June 2027 inclusive = 11 months.
        $this->assertSame(11, $invoice->installments()->count());
    }

    public function test_monthly_schedule_amount_splits_evenly_with_last_period_absorbing_the_remainder(): void
    {
        $invoice = $this->invoice('1000.00');
        $this->service()->generateCalendarSchedule($invoice, 'monthly', '2026-08-15', $this->year->end_date->toDateString());

        $amounts = $invoice->installments()->orderBy('sequence')->pluck('amount')->all();
        $this->assertCount(11, $amounts);
        // 1000.00 / 11 = 90.909... -> 90.90 each, last absorbs the remainder.
        $this->assertSame(array_fill(0, 10, '90.90'), array_slice($amounts, 0, 10));
        $this->assertSame('91.00', $amounts[10]);
        // Bcmath-exact: the parts sum back to the original total.
        $this->assertSame('1000.00', array_reduce($amounts, fn ($c, $a) => bcadd($c, $a, 2), '0.00'));
    }

    public function test_monthly_schedule_due_dates_land_on_calendar_boundaries_after_the_first(): void
    {
        $invoice = $this->invoice('1100.00');
        $this->service()->generateCalendarSchedule($invoice, 'monthly', '2026-08-15', $this->year->end_date->toDateString());

        $dueDates = $invoice->installments()->orderBy('sequence')->pluck('due_date')->map(fn ($d) => $d->toDateString())->all();
        // First installment due NOW (the registration date itself, mid-month
        // — the starting period is due immediately, not deferred to a
        // future calendar boundary).
        $this->assertSame('2026-08-15', $dueDates[0]);
        // Every subsequent installment lands on the 1st of its own calendar
        // month — a real boundary, never $start + N*30 days.
        $this->assertSame('2026-09-01', $dueDates[1]);
        $this->assertSame('2026-10-01', $dueDates[2]);
    }

    public function test_monthly_first_period_is_charged_in_full_with_no_proration_for_mid_month_registration(): void
    {
        $invoiceLate = $this->invoice('1000.00');
        $this->service()->generateCalendarSchedule($invoiceLate, 'monthly', '2026-08-28', $this->year->end_date->toDateString());
        $invoiceEarly = $this->invoice('1000.00');
        $this->service()->generateCalendarSchedule($invoiceEarly, 'monthly', '2026-08-01', $this->year->end_date->toDateString());

        // Registering on the 28th vs the 1st of the same starting month
        // must NOT change the first installment's amount — no proration.
        $firstLate = $invoiceLate->installments()->orderBy('sequence')->first();
        $firstEarly = $invoiceEarly->installments()->orderBy('sequence')->first();
        $this->assertSame($firstEarly->amount, $firstLate->amount);
    }

    // ----- quarterly -----------------------------------------------------------

    public function test_quarterly_schedule_uses_calendar_quarter_boundaries(): void
    {
        $invoice = $this->invoice('1200.00');
        // Registration in August (Q3: Jul-Sep) through year end June (Q2:
        // Apr-Jun) -> Q3 2026, Q4 2026, Q1 2027, Q2 2027 = 4 quarters.
        $this->service()->generateCalendarSchedule($invoice, 'quarterly', '2026-08-15', $this->year->end_date->toDateString());

        $installments = $invoice->installments()->orderBy('sequence')->get();
        $this->assertSame(4, $installments->count());
        $this->assertSame('2026-08-15', $installments[0]->due_date->toDateString());
        $this->assertSame('2026-10-01', $installments[1]->due_date->toDateString());
        $this->assertSame('2027-01-01', $installments[2]->due_date->toDateString());
        $this->assertSame('2027-04-01', $installments[3]->due_date->toDateString());
        $this->assertSame('1200.00', $installments->reduce(fn ($c, $i) => bcadd($c, $i->amount, 2), '0.00'));
    }

    // ----- yearly ----------------------------------------------------------

    public function test_yearly_schedule_produces_a_single_full_amount_installment(): void
    {
        $invoice = $this->invoice('1500.00');
        $this->service()->generateCalendarSchedule($invoice, 'yearly', '2026-08-15', $this->year->end_date->toDateString());

        $installments = $invoice->installments()->orderBy('sequence')->get();
        $this->assertCount(1, $installments);
        $this->assertSame('1500.00', $installments->first()->amount);
        $this->assertSame('2026-08-15', $installments->first()->due_date->toDateString());
    }

    // ----- mid-year enrollment / schedule boundaries --------------------------

    public function test_mid_year_enrollment_produces_a_full_schedule_from_the_starting_period_through_year_end(): void
    {
        $invoice = $this->invoice('600.00');
        // Registering in March, year ends June -> March, April, May, June = 4 months.
        $this->service()->generateCalendarSchedule($invoice, 'monthly', '2027-03-10', $this->year->end_date->toDateString());

        $this->assertSame(4, $invoice->installments()->count());
        $this->assertSame('2027-03-10', $invoice->installments()->orderBy('sequence')->first()->due_date->toDateString());
    }

    public function test_registration_in_the_final_eligible_month_still_produces_a_valid_single_installment_schedule(): void
    {
        $invoice = $this->invoice('300.00');
        // Year ends 2027-06-30; registering within that same final month.
        $this->service()->generateCalendarSchedule($invoice, 'monthly', '2027-06-20', $this->year->end_date->toDateString());

        $installments = $invoice->installments()->orderBy('sequence')->get();
        $this->assertCount(1, $installments);
        $this->assertSame('300.00', $installments->first()->amount);
        $this->assertSame('2027-06-20', $installments->first()->due_date->toDateString());
    }

    public function test_registration_exactly_on_a_month_boundary_behaves_correctly(): void
    {
        $invoice = $this->invoice('500.00');
        $this->service()->generateCalendarSchedule($invoice, 'monthly', '2026-09-01', $this->year->end_date->toDateString());

        $installments = $invoice->installments()->orderBy('sequence')->get();
        // September through June inclusive = 10 months.
        $this->assertCount(10, $installments);
        $this->assertSame('2026-09-01', $installments->first()->due_date->toDateString());
        $this->assertSame('2026-10-01', $installments[1]->due_date->toDateString());
    }
}
