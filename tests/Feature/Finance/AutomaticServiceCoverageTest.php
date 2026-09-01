<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InstallmentCoveragePeriod;
use App\Models\ServiceCoverage;
use App\Services\Finance\InvoiceIssuanceService;
use Illuminate\Validation\ValidationException;

/**
 * Finance V2, Phase 2D items 2/3/5 (docs/finance-v2-architecture.md).
 *
 * Automatic ServiceCoverage creation for monthly calendar-billed invoices,
 * the explicit installment<->period<->coverage mapping (installment_coverage_periods),
 * and Food's daily coverage granularity coexisting with monthly collection.
 */
class AutomaticServiceCoverageTest extends FinanceOperationsTestCase
{
    private function monthlyFee(string $category = Fee::CATEGORY_TUITION, array $priceDimensions = []): Fee
    {
        $fee = Fee::create(['name_ru' => 'Услуга (авто-покрытие)', 'category' => $category, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        // Tuition-category items auto-snapshot the enrollment's real
        // grade_id into their metadata (InvoiceIssuanceService::issue(),
        // when grade_group is blank) even though the price resolver itself
        // tolerates a grade-agnostic (null grade_id) price via its
        // whereNull OR-branch — ServiceCoverageService::sourceTariff()'s
        // stricter per-field consistency check does NOT tolerate that
        // mismatch, so the fixture price must match the enrollment's own
        // grade_id for Tuition, exactly like a real dimensioned tariff
        // would in production.
        $dimensions = $category === Fee::CATEGORY_TUITION ? ['grade_id' => $this->enrollment->grade_id] : [];
        FeePrice::create($dimensions + $priceDimensions + [
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly',
            'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);

        return $fee;
    }

    private function issue(Fee $fee, string $pricingDate = '2026-09-17'): Invoice
    {
        return app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => $pricingDate,
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);
    }

    public function test_automatic_coverage_is_created_for_a_monthly_calendar_invoice(): void
    {
        $fee = $this->monthlyFee();
        $invoice = $this->issue($fee);

        $this->assertSame(1, ServiceCoverage::where('fee_id', $fee->id)->count());
        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();
        $this->assertSame('monthly', $coverage->billing_unit);
        $this->assertSame($invoice->items->sole()->id, $coverage->invoice_item_id);
    }

    public function test_coverage_start_is_the_calendar_month_not_the_due_date_for_mid_month_registration(): void
    {
        $fee = $this->monthlyFee();
        $invoice = $this->issue($fee, '2026-09-17');

        $firstInstallment = $invoice->installments()->orderBy('sequence')->first();
        $this->assertSame('2026-09-17', $firstInstallment->due_date->toDateString(), 'due date is the actual registration date (no proration)');

        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();
        $this->assertSame('2026-09-01', $coverage->coverage_start->toDateString(), 'coverage_start is the calendar month start, never the due date');

        $period = InstallmentCoveragePeriod::where('invoice_installment_id', $firstInstallment->id)->sole();
        $this->assertSame('2026-09-01', $period->period_start->toDateString());
        $this->assertSame('2026-09-30', $period->period_end->toDateString());
    }

    public function test_one_installment_coverage_period_row_per_generated_installment(): void
    {
        $fee = $this->monthlyFee();
        $invoice = $this->issue($fee, '2026-09-17');

        // September 2026 through June 2027 inclusive = 10 months.
        $this->assertSame(10, $invoice->installments()->count());
        $this->assertSame(10, InstallmentCoveragePeriod::count());
        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();
        $this->assertSame('2027-06-30', $coverage->coverage_end->toDateString());
    }

    public function test_due_date_change_does_not_rewrite_coverage_period_meaning(): void
    {
        $fee = $this->monthlyFee();
        $invoice = $this->issue($fee, '2026-09-17');
        $installment = $invoice->installments()->orderBy('sequence')->first();
        $period = InstallmentCoveragePeriod::where('invoice_installment_id', $installment->id)->sole();
        $originalStart = $period->period_start->toDateString();
        $originalEnd = $period->period_end->toDateString();

        $installment->forceFill(['due_date' => '2026-10-05'])->saveQuietly();

        $period->refresh();
        $this->assertSame($originalStart, $period->period_start->toDateString(), 'period_start is unaffected by a later due_date change');
        $this->assertSame($originalEnd, $period->period_end->toDateString());
    }

    public function test_installment_coverage_period_row_is_immutable(): void
    {
        $fee = $this->monthlyFee();
        $invoice = $this->issue($fee, '2026-09-17');
        $period = InstallmentCoveragePeriod::first();

        $this->expectException(\LogicException::class);
        $period->update(['period_start' => '2020-01-01']);
    }

    public function test_credit_application_does_not_touch_installment_coverage_periods(): void
    {
        $fee = $this->monthlyFee();
        $invoice = $this->issue($fee, '2026-09-17');
        $countBefore = InstallmentCoveragePeriod::count();

        // A real StudentCredit, sourced from an actual tariff decrease
        // through the coverage this test's own invoice just automatically
        // created — source_adjustment_id is a required FK, never nullable.
        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();
        FeePrice::where('fee_id', $fee->id)->update(['end_date' => '2026-09-30']);
        $decrease = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '800.00', 'currency' => 'EGP', 'start_date' => '2026-10-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        app(\App\Services\Finance\TariffAdjustmentService::class)->approve($coverage->fresh(), $decrease, $this->accountant);
        $credit = \App\Models\StudentCredit::sole();

        $otherInvoice = $this->invoice('300.00');
        app(\App\Services\Finance\StudentCreditService::class)->apply($credit, $otherInvoice, '300.00', (string) \Illuminate\Support\Str::uuid(), $this->accountant);

        $this->assertSame($countBefore, InstallmentCoveragePeriod::count());
    }

    public function test_transaction_rollback_leaves_no_orphan_coverage_or_period_rows(): void
    {
        $fee = $this->monthlyFee();
        // due_date is required but irrelevant here — force a failure deep
        // inside issue() by requesting a billing_period the fee does not
        // allow, so the whole transaction (invoice + items + schedule +
        // coverage) rolls back before coverage would ever be created.
        try {
            app(InvoiceIssuanceService::class)->issue($this->student, [
                'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
                'due_date' => '2027-06-30', 'pricing_date' => '2026-09-17',
                'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
                'payment_type' => 'calendar', 'billing_period' => 'quarterly',
            ], $this->accountant);
            $this->fail('Expected ValidationException — fee only allows monthly.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, ServiceCoverage::count());
        $this->assertSame(0, InstallmentCoveragePeriod::count());
    }

    public function test_retry_leaves_no_duplicate_coverage_rows(): void
    {
        // Two independent full submissions for the SAME fee — each is a
        // distinct real invoice (issue() has no invoice-level dedup, an
        // accepted M3 boundary), so this proves coverage creation itself
        // never duplicates WITHIN one issuance, not that two submissions
        // collapse into one (that is explicitly out of scope, see the
        // Phase 2D report).
        $fee = $this->monthlyFee();
        $this->issue($fee, '2026-09-17');
        $this->issue($fee, '2026-09-17');

        $this->assertSame(2, ServiceCoverage::where('fee_id', $fee->id)->count(), 'two real invoices correctly get two coverage rows, never a spurious third');
        foreach (ServiceCoverage::where('fee_id', $fee->id)->get() as $coverage) {
            $this->assertSame(10, InstallmentCoveragePeriod::where('service_coverage_id', $coverage->id)->count());
        }
    }

    public function test_bundled_fees_sharing_one_schedule_each_get_their_own_coverage_with_identical_period_boundaries(): void
    {
        $tuition = $this->monthlyFee(Fee::CATEGORY_TUITION);
        $transport = Fee::create(['name_ru' => 'Трансфер', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $transport->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $transport->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '400.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-17',
            'items' => [
                ['fee_id' => $tuition->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null],
                ['fee_id' => $transport->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1'],
            ],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);

        $this->assertSame(2, ServiceCoverage::count());
        $firstInstallment = $invoice->installments()->orderBy('sequence')->first();
        $periods = InstallmentCoveragePeriod::where('invoice_installment_id', $firstInstallment->id)->get();
        $this->assertCount(2, $periods, 'one row per bundled Fee, sharing the same installment');
        $this->assertSame('2026-09-01', $periods[0]->period_start->toDateString());
        $this->assertSame('2026-09-01', $periods[1]->period_start->toDateString(), 'identical period boundaries across both Fees for the same shared installment');
    }

    // ----- Food: daily coverage granularity, multi-month collection --------

    public function test_food_gets_daily_coverage_when_a_real_daily_tariff_exists(): void
    {
        $food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
        $food->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $food->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => 'Стандарт', 'amount' => '50.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-17',
            'items' => [['fee_id' => $food->id, 'grade_group' => null, 'payment_period' => 'daily', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'meal_plan', 'option_value' => 'Стандарт']],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);

        $coverage = ServiceCoverage::where('fee_id', $food->id)->sole();
        $this->assertSame('daily', $coverage->billing_unit, 'Food gets daily coverage granularity even though the SCHEDULE cadence is monthly');
        // Collection cadence stays monthly regardless of coverage granularity.
        $this->assertSame(10, $invoice->installments()->count());
    }

    public function test_food_with_only_a_monthly_tariff_gets_no_automatic_coverage_and_does_not_block_issuance(): void
    {
        // Honest, reported gap (Phase 2D item 5): a Food Fee priced with a
        // monthly-only tariff (no genuine daily FeePrice) cannot satisfy
        // ServiceCoverageService::record()'s existing payment_period match
        // requirement for billing_unit='daily' — coverage creation is
        // skipped for this Fee, but the invoice itself still issues fine.
        $food = Fee::create(['name_ru' => 'Питание (только месячный тариф)', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
        $food->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $food->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'meal_plan', 'option_value' => 'Стандарт', 'amount' => '900.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-17',
            'items' => [['fee_id' => $food->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'meal_plan', 'option_value' => 'Стандарт']],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);

        // Invoice issuance itself succeeded despite the coverage gap.
        $this->assertNotNull($invoice->id);
        $this->assertSame(10, $invoice->installments()->count());
        $this->assertSame(0, ServiceCoverage::where('fee_id', $food->id)->count(), 'coverage skipped, not fabricated with the wrong billing_unit');
    }
}
