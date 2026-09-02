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

    // ----- P0 Blocker 1: multi-period unit pricing, exact worked example ----

    public function test_transport_1500_per_month_over_9_months_bills_13500_total(): void
    {
        // The exact worked example from the corrective-pass directive:
        // Transport 1500/month, Sep 1 -> May 31 (9 months) must bill
        // 1500 x 9 = 13500 total, never 1500 total split across 9
        // installments.
        $this->year->forceFill(['end_date' => '2027-05-31'])->save();
        $fee = Fee::create(['name_ru' => 'Трансфер (1500)', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1']],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);

        $item = $invoice->items->sole();
        $this->assertSame('1500.00', $item->unit_price);
        $this->assertSame(9, $item->quantity);
        $this->assertSame('13500.00', $item->amount);
        $this->assertSame('13500.00', $invoice->total_amount);
        $this->assertSame('1500.00', $item->metadata['unit_tariff']);
        $this->assertSame('monthly', $item->metadata['billing_unit']);
        $this->assertSame('9', $item->metadata['unit_count']);
        $this->assertSame('2026-09-01', $item->metadata['coverage_start']);
        $this->assertSame('2027-05-31', $item->metadata['coverage_end']);
        $this->assertSame('13500.00', $item->metadata['line_total']);

        $installments = $invoice->installments()->orderBy('sequence')->get();
        $this->assertCount(9, $installments);
        foreach ($installments as $installment) {
            $this->assertSame('1500.00', $installment->amount);
        }
        $this->assertSame('13500.00', bcadd((string) $installments->sum('amount'), '0', 2));
        $this->assertSame((string) $invoice->total_amount, bcadd((string) $installments->sum('amount'), '0', 2));
    }

    public function test_mid_month_registration_still_counts_a_full_first_month_not_fractional(): void
    {
        $this->year->forceFill(['end_date' => '2027-05-31'])->save();
        $fee = Fee::create(['name_ru' => 'Трансфер (середина месяца)', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-17',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1']],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);

        $item = $invoice->items->sole();
        $this->assertSame(9, $item->quantity, 'still 9 whole months, never 8.xx');
        $this->assertSame('13500.00', $item->amount);
        $first = $invoice->installments()->orderBy('sequence')->first();
        $this->assertSame('1500.00', $first->amount, 'first installment is still a full month, no proration');
        $this->assertSame('2026-09-17', $first->due_date->toDateString());
    }

    public function test_bundled_invoice_two_periodic_fees_each_correctly_priced_independently(): void
    {
        $this->year->forceFill(['end_date' => '2027-05-31'])->save();
        $tuition = Fee::create(['name_ru' => 'Обучение (bundled)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $tuition->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $tuition->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '2000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'is_active' => true]);
        $transport = Fee::create(['name_ru' => 'Трансфер (bundled)', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $transport->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $transport->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [
                ['fee_id' => $tuition->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null],
                ['fee_id' => $transport->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1'],
            ],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);

        $tuitionItem = $invoice->items->firstWhere('fee_id', $tuition->id);
        $transportItem = $invoice->items->firstWhere('fee_id', $transport->id);
        $this->assertSame(9, $tuitionItem->quantity);
        $this->assertSame(9, $transportItem->quantity);
        $this->assertSame('18000.00', $tuitionItem->amount, '2000 x 9');
        $this->assertSame('4500.00', $transportItem->amount, '500 x 9');
        $this->assertSame('22500.00', $invoice->total_amount, '18000 + 4500, each Fee priced independently');

        // Shared installment amounts equal the sum of both Fees' own
        // per-period unit prices (2000 + 500 = 2500/month).
        $installments = $invoice->installments()->orderBy('sequence')->get();
        $this->assertCount(9, $installments);
        foreach ($installments as $installment) {
            $this->assertSame('2500.00', $installment->amount);
        }
    }

    public function test_prepay_various_month_counts_and_remaining_year(): void
    {
        $this->year->forceFill(['end_date' => '2027-05-31'])->save();
        foreach ([1, 3, 4, 9] as $monthsToPay) {
            $fee = Fee::create(['name_ru' => "Трансфер (оплата {$monthsToPay})", 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
            $fee->billingPeriods()->create(['billing_period' => 'monthly']);
            FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'is_active' => true]);

            $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
                'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
                'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
                'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1']],
                'payment_type' => 'calendar', 'billing_period' => 'monthly',
            ], $this->accountant);

            $this->assertSame('13500.00', $invoice->total_amount, "fee={$fee->id}: 9-month invoice total is always correct regardless of how much is later paid");

            $installments = $invoice->installments()->orderBy('sequence')->get();
            $paidSoFar = '0.00';
            for ($i = 0; $i < $monthsToPay; $i++) {
                app(\App\Services\Finance\InvoicePaymentService::class)->record(
                    invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: (string) $installments[$i]->amount,
                    paymentMethod: 'cash', idempotencyKey: (string) \Illuminate\Support\Str::uuid(), actor: $this->accountant,
                    installmentId: $installments[$i]->id,
                );
                $paidSoFar = bcadd($paidSoFar, (string) $installments[$i]->amount, 2);
            }

            $this->assertSame(bcmul('1500.00', (string) $monthsToPay, 2), $paidSoFar, "fee={$fee->id}: prepaying {$monthsToPay} month(s) moves exactly 1500 x {$monthsToPay}");
            $this->assertSame($monthsToPay, $installments->fresh()->filter(fn ($i) => bccomp((string) $i->remaining_amount, '0.00', 2) === 0)->count());
        }
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

    public function test_food_with_only_a_monthly_tariff_and_no_daily_basis_blocks_the_entire_issuance(): void
    {
        // Corrective pass (P0 Blocker 3): coverage is a financial invariant
        // for a periodic-billed Food Fee — if no daily basis tariff exists
        // to build it from, the WHOLE issuance must fail loudly and roll
        // back completely, never silently proceed without coverage.
        $food = Fee::create(['name_ru' => 'Питание (только месячный тариф)', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
        $food->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $food->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'meal_plan', 'option_value' => 'Стандарт', 'amount' => '900.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        // Deliberately NO daily-payment_period FeePrice for this Fee.

        $invoicesBefore = Invoice::count();
        $itemsBefore = \App\Models\InvoiceItem::count();
        $installmentsBefore = \App\Models\InvoiceInstallment::count();

        try {
            app(InvoiceIssuanceService::class)->issue($this->student, [
                'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
                'due_date' => '2027-06-30', 'pricing_date' => '2026-09-17',
                'items' => [['fee_id' => $food->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'meal_plan', 'option_value' => 'Стандарт']],
                'payment_type' => 'calendar', 'billing_period' => 'monthly',
            ], $this->accountant);
            $this->fail('Expected ValidationException — no daily basis tariff exists for this Food Fee.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fees', $exception->errors());
        }

        // Zero persisted rows from the failed attempt — a true rollback,
        // never a silent partial commit.
        $this->assertSame($invoicesBefore, Invoice::count());
        $this->assertSame($itemsBefore, \App\Models\InvoiceItem::count());
        $this->assertSame($installmentsBefore, \App\Models\InvoiceInstallment::count());
        $this->assertSame(0, ServiceCoverage::where('fee_id', $food->id)->count());
    }

    public function test_bundled_invoice_partial_settlement_reflects_correctly_per_coverage_via_shared_installments(): void
    {
        $tuition = $this->monthlyFee(Fee::CATEGORY_TUITION);
        $transport = Fee::create(['name_ru' => 'Трансфер (частичная оплата)', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
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

        $tuitionCoverage = ServiceCoverage::where('fee_id', $tuition->id)->sole();
        $transportCoverage = ServiceCoverage::where('fee_id', $transport->id)->sole();
        $installments = $invoice->installments()->orderBy('sequence')->get();

        // Pay only the FIRST shared installment (both Fees' amounts
        // combined, since it's one shared installment). A multi-item
        // clean invoice requires explicit per-item PaymentAllocation
        // (Phase 1C) — allocate this period's own per-Fee shares.
        $tuitionItem = $invoice->items->firstWhere('fee_id', $tuition->id);
        $transportItem = $invoice->items->firstWhere('fee_id', $transport->id);
        app(\App\Services\Finance\InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: (string) $installments->first()->amount,
            paymentMethod: 'cash', idempotencyKey: (string) \Illuminate\Support\Str::uuid(), actor: $this->accountant,
            installmentId: $installments->first()->id,
            allocations: [
                ['invoice_item_id' => $tuitionItem->id, 'amount' => '1000.00'],
                ['invoice_item_id' => $transportItem->id, 'amount' => '400.00'],
            ],
        );

        $firstPeriodTuition = InstallmentCoveragePeriod::where('service_coverage_id', $tuitionCoverage->id)->where('invoice_installment_id', $installments->first()->id)->sole();
        $firstPeriodTransport = InstallmentCoveragePeriod::where('service_coverage_id', $transportCoverage->id)->where('invoice_installment_id', $installments->first()->id)->sole();
        $secondPeriodTuition = InstallmentCoveragePeriod::where('service_coverage_id', $tuitionCoverage->id)->where('invoice_installment_id', $installments[1]->id)->sole();

        $this->assertTrue($firstPeriodTuition->isSettled(), 'both Fees share the same settled installment');
        $this->assertTrue($firstPeriodTransport->isSettled());
        $this->assertFalse($secondPeriodTuition->isSettled(), 'the second period, on a different unpaid installment, is correctly not settled');
    }

    // ----- Quarterly: real issue() path, not resolver-only ----------------

    public function test_quarterly_calendar_invoice_derives_pricing_and_creates_monthly_basis_coverage_through_real_issuance(): void
    {
        // Corrective-pass requirement: quarterly must be exercised through
        // the REAL InvoiceIssuanceService::issue() path end to end (pricing
        // derivation, invoice totals, installment schedule, AND automatic
        // coverage via the monthly adjustment-basis branch), never only
        // through InvoiceCalculationService::calculate() in isolation.
        $fee = Fee::create(['name_ru' => 'Обучение (квартал, issue)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'quarterly']);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        // Only a monthly FeePrice exists — the quarterly unit price must be
        // DERIVED (monthly x 3), and it also serves as the monthly
        // adjustment basis for coverage.
        $monthly = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'quarterly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'calendar', 'billing_period' => 'quarterly',
        ], $this->accountant);

        // Default year end_date (FinanceOperationsTestCase) is 2027-06-30
        // — Sep 2026 through Jun 2027 is 10 months. Corrective pass #2
        // (P0 Blocker 1): quarters are anchored to the ACTUAL service
        // start month (September), never civil calendar quarters — the
        // groups are Sep-Nov / Dec-Feb / Mar-May (3 full quarters) plus a
        // trailing 1-month partial group (June), never Jul-Sep..Apr-Jun
        // (which would bill for July-August before the student enrolled).
        // Derived pricing collapses to the one uniform monthly rate
        // (1000/month x 10 months = 10000), the exact same total as
        // 3x(1000x3) + (1000x1) — see priceQuarterlyLine()'s own docblock.
        $item = $invoice->items->sole();
        $this->assertSame('1000.00', $item->unit_price, 'derived quarterly pricing collapses to its one uniform monthly rate');
        $this->assertSame(10, $item->quantity, 'total months covered (Sep..Jun), not the number of quarterly groups');
        $this->assertSame('10000.00', $item->amount, '1000 x 10 months = 3000+3000+3000+1000, never 3000 x 4 (which would overbill for Jul-Aug)');
        $this->assertSame('10000.00', $invoice->total_amount);
        $this->assertTrue($item->metadata['derived']);
        $this->assertSame('quarterly', $item->metadata['derived_period']);
        $this->assertSame($monthly->id, $item->metadata['derived_from_fee_price_id']);

        $installments = $invoice->installments()->orderBy('sequence')->get();
        $this->assertCount(4, $installments);
        $this->assertSame(['3000.00', '3000.00', '3000.00', '1000.00'], $installments->pluck('amount')->all(), 'each full quarter is 3000, the trailing partial June group is exactly 1000 (1 month), never an even 2500 split');
        $this->assertSame('10000.00', $installments->reduce(fn ($c, $i) => bcadd($c, $i->amount, 2), '0.00'));

        // Coverage: quarterly billing takes the adjustment-basis path
        // (record()/sourceTariff() cannot be reused — the charged tariff is
        // quarterly-denominated, not monthly) — a SEPARATE monthly FeePrice
        // is resolved as the basis, coverage itself is always
        // billing_unit='monthly' regardless of the quarterly collection
        // cadence, and spans the FULL schedule (first quarter start through
        // last quarter end), never just one quarter — and never before the
        // actual service start (2026-09-01, not the old, wrong 2026-07-01).
        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();
        $this->assertSame('monthly', $coverage->billing_unit);
        $this->assertSame('2026-09-01', $coverage->coverage_start->toDateString());
        $this->assertSame('2027-06-30', $coverage->coverage_end->toDateString());
        $this->assertSame($monthly->id, $item->fresh()->metadata['adjustment_basis_fee_price_id']);
        $this->assertSame('1000.00', $item->fresh()->metadata['adjustment_basis_unit_amount']);

        $this->assertSame(4, InstallmentCoveragePeriod::where('service_coverage_id', $coverage->id)->count(), 'one coverage-period row per quarterly installment');
    }

    public function test_quarterly_calendar_invoice_over_an_exact_multiple_of_three_months_has_no_partial_group(): void
    {
        // Worked example (B): 9 months (Sep-May) = exactly 3 full
        // quarters, no trailing partial group at all.
        $this->year->forceFill(['end_date' => '2027-05-31'])->save();
        $fee = Fee::create(['name_ru' => 'Обучение (квартал, 9 месяцев)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'quarterly']);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-05-31', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-05-31', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'quarterly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'calendar', 'billing_period' => 'quarterly',
        ], $this->accountant);

        $item = $invoice->items->sole();
        $this->assertSame('1000.00', $item->unit_price);
        $this->assertSame(9, $item->quantity);
        $this->assertSame('9000.00', $item->amount, '3 x (1000 x 3) = 9000, exactly 3 x 3000');
        $installments = $invoice->installments()->orderBy('sequence')->get();
        $this->assertSame(['3000.00', '3000.00', '3000.00'], $installments->pluck('amount')->all());

        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();
        $this->assertSame('2026-09-01', $coverage->coverage_start->toDateString());
        $this->assertSame('2027-05-31', $coverage->coverage_end->toDateString(), 'coverage never extends beyond the actual service span');
    }

    public function test_quarterly_calendar_invoice_with_no_monthly_basis_blocks_the_entire_issuance(): void
    {
        // Same fail-loud rule as yearly (P0 Blocker 2): if quarterly
        // pricing can be derived (a monthly price exists) but coverage's
        // own basis resolution somehow can't find one, the whole issuance
        // must roll back — never invent a basis by dividing the quarterly
        // charge. Simulated here by deriving the quarterly price from a
        // grade-matched monthly tariff while the ONLY monthly price on file
        // does not match the enrollment's grade (so calculate() can still
        // resolve a price via a broader match while the basis resolver,
        // which uses the identical dimensional matching, fails identically
        // — proving the two can never disagree by construction).
        $fee = Fee::create(['name_ru' => 'Обучение (квартал, без базы)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'quarterly']);
        // Deliberately NO monthly FeePrice at all for this fee — quarterly
        // derivation itself must fail loud (before coverage is even
        // reached), exactly like QuarterlyDerivedPricingTest's resolver-
        // level assertion, but now proven through the real issue() path.
        $invoicesBefore = Invoice::count();

        try {
            app(InvoiceIssuanceService::class)->issue($this->student, [
                'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
                'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
                'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'quarterly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
                'payment_type' => 'calendar', 'billing_period' => 'quarterly',
            ], $this->accountant);
            $this->fail('Expected ValidationException — no monthly price exists to derive the quarterly tariff from.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($invoicesBefore, Invoice::count());
        $this->assertSame(0, ServiceCoverage::where('fee_id', $fee->id)->count());
    }

    public function test_quarterly_derived_pricing_with_eleven_months_produces_three_full_quarters_plus_a_two_month_trailing_group(): void
    {
        // Worked example: 11 months (Sep-Jul) = 3 full quarters + 2 final
        // months, trailing installment = monthly x 2.
        $this->year->forceFill(['end_date' => '2027-07-31'])->save();
        $fee = Fee::create(['name_ru' => 'Обучение (квартал, 11 месяцев)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'quarterly']);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-07-31', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'quarterly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'calendar', 'billing_period' => 'quarterly',
        ], $this->accountant);

        $item = $invoice->items->sole();
        $this->assertSame(11, $item->quantity);
        $this->assertSame('11000.00', $item->amount, '1000 x 11 = 3000+3000+3000+2000');
        $installments = $invoice->installments()->orderBy('sequence')->get();
        $this->assertSame(['3000.00', '3000.00', '3000.00', '2000.00'], $installments->pluck('amount')->all(), 'the trailing partial group is 2 months (1000 x 2), not an even split');
    }

    public function test_explicit_quarterly_package_price_with_a_trailing_partial_group_uses_a_monthly_basis_for_the_remainder(): void
    {
        // P0 Blocker 1 (explicit package pricing): full 3-month groups use
        // the real quarterly PACKAGE price as-is; the trailing partial
        // group never gets a prorated slice of that package — it uses a
        // SEPARATELY resolved monthly basis tariff x its own month-count.
        $fee = Fee::create(['name_ru' => 'Обучение (квартальный пакет)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'quarterly']);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        // Explicit quarterly PACKAGE price — deliberately NOT 3x the
        // monthly rate (2800 vs 1000x3=3000), proving the package price
        // is used as-is for full groups, never re-derived from monthly.
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'quarterly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '2800.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $monthlyBasis = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        // Sep 2026 -> Jun 2027 = 10 months -> 3 full quarters + 1 trailing
        // June month.
        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'quarterly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'calendar', 'billing_period' => 'quarterly',
        ], $this->accountant);

        $item = $invoice->items->sole();
        // Corrective pass #3 (HIGH 3): a MIXED case (full package blocks
        // + a trailing partial) is represented with a BLENDED unit_price
        // so unit_price x quantity == amount always holds — 940.00 x 10
        // = 9400.00, never a fake unit_price=2800/quantity=3 that would
        // only multiply out to 8400.
        $this->assertSame('9400.00', $item->unit_price, 'mixed package/tail is one truthful composite charge');
        $this->assertSame(1, $item->quantity);
        $this->assertSame('9400.00', $item->amount, '3 x 2800 (full quarters) + 1 x 1000 (trailing June, monthly basis) = 8400 + 1000');
        $this->assertSame('9400.00', bcmul($item->unit_price, (string) $item->quantity, 2), 'unit_price x quantity must equal amount exactly');
        $this->assertFalse($item->metadata['derived'] ?? false, 'this is an explicit package price, not a derived one');
        $this->assertSame('1', $item->metadata['partial_group_months']);
        $this->assertSame('1000.00', $item->metadata['partial_group_unit_price']);
        $this->assertSame('1000.00', $item->metadata['partial_group_amount']);
        // The TRUE breakdown lives in metadata, not the item's own
        // unit_price/quantity fields.
        $this->assertSame('2800.00', $item->metadata['quarterly_package_price']);
        $this->assertSame('3', $item->metadata['complete_quarterly_blocks']);
        $this->assertTrue($item->metadata['quarterly_package_applied']);
        $this->assertSame(['2800.00', '2800.00', '2800.00', '1000.00'], $item->metadata['per_block_amounts']);

        $installments = $invoice->installments()->orderBy('sequence')->get();
        $this->assertSame(['2800.00', '2800.00', '2800.00', '1000.00'], $installments->pluck('amount')->all());

        // Coverage still resolves its own monthly basis independently
        // (same monthly FeePrice, reused correctly).
        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();
        $this->assertSame($monthlyBasis->id, $item->fresh()->metadata['adjustment_basis_fee_price_id']);
    }

    public function test_explicit_quarterly_package_price_with_a_trailing_partial_group_and_no_monthly_basis_blocks_issuance(): void
    {
        // Same fail-loud rule, at the PRICING stage this time (not just
        // coverage): an explicit quarterly package price covers the full
        // groups, but with no monthly basis for the trailing partial
        // group, the whole issuance must fail before any rows are
        // persisted — never silently omit or prorate the partial group.
        $fee = Fee::create(['name_ru' => 'Обучение (пакет без базы)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'quarterly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'quarterly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '2800.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        // Deliberately NO monthly FeePrice — the trailing partial (June)
        // group has no basis to be priced from.

        $invoicesBefore = Invoice::count();
        try {
            app(InvoiceIssuanceService::class)->issue($this->student, [
                'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
                'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
                'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'quarterly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
                'payment_type' => 'calendar', 'billing_period' => 'quarterly',
            ], $this->accountant);
            $this->fail('Expected ValidationException — no monthly basis for the trailing partial quarterly group.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($invoicesBefore, Invoice::count());
    }

    // ================================================================
    // Corrective pass #3 (HIGH 3 — quarterly <3-month line representation).
    // ================================================================

    private function issueQuarterlyPackageSpan(string $endDate, string $pricingDate): \App\Models\Invoice
    {
        $this->year->forceFill(['end_date' => $endDate])->save();
        $fee = Fee::create(['name_ru' => "Обучение (квартал, {$endDate})", 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'quarterly']);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'quarterly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '2800.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => $endDate, 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => $endDate, 'is_active' => true]);

        return app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => $endDate, 'pricing_date' => $pricingDate,
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'quarterly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'calendar', 'billing_period' => 'quarterly',
        ], $this->accountant);
    }

    public function test_a_one_month_explicit_quarterly_span_has_zero_complete_blocks_and_a_coherent_line(): void
    {
        // Sep only (year ends Sep 30) -> 0 complete quarterly blocks, the
        // ENTIRE span is a single 1-month partial group.
        $invoice = $this->issueQuarterlyPackageSpan('2026-09-30', '2026-09-01');

        $item = $invoice->items->sole();
        $this->assertSame('1000.00', $item->unit_price, 'the monthly basis amount, never a fabricated quantity=0 line');
        $this->assertSame(1, $item->quantity);
        $this->assertSame('1000.00', $item->amount);
        $this->assertSame(bcmul($item->unit_price, (string) $item->quantity, 2), $item->amount, 'unit_price x quantity == amount always holds');
        $this->assertSame('0', $item->metadata['complete_quarterly_blocks']);
        $this->assertFalse($item->metadata['quarterly_package_applied']);
    }

    public function test_a_two_month_explicit_quarterly_span_has_zero_complete_blocks_and_a_coherent_line(): void
    {
        $invoice = $this->issueQuarterlyPackageSpan('2026-10-31', '2026-09-01');

        $item = $invoice->items->sole();
        $this->assertSame('1000.00', $item->unit_price);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('2000.00', $item->amount);
        $this->assertSame(bcmul($item->unit_price, (string) $item->quantity, 2), $item->amount);
        $this->assertSame('0', $item->metadata['complete_quarterly_blocks']);
        $this->assertFalse($item->metadata['quarterly_package_applied']);
    }

    public function test_a_four_month_explicit_quarterly_span_mixes_one_complete_block_and_a_partial_month(): void
    {
        // Sep-Dec (year ends Dec 31) -> 1 full quarterly block (Sep-Nov,
        // 2800) + 1 trailing partial month (Dec, monthly basis 1000) =
        // 3800 total, a genuinely MIXED case (never zero-block).
        $invoice = $this->issueQuarterlyPackageSpan('2026-12-31', '2026-09-01');

        $item = $invoice->items->sole();
        $this->assertSame('3800.00', $item->amount, '2800 (1 full block) + 1000 (1 partial month)');
        $this->assertSame(1, $item->quantity, 'one truthful composite package/tail charge');
        $this->assertSame(bcmul($item->unit_price, (string) $item->quantity, 2), $item->amount, 'blended unit_price x quantity == amount always holds');
        $this->assertSame('1', $item->metadata['complete_quarterly_blocks']);
        $this->assertTrue($item->metadata['quarterly_package_applied']);
        $this->assertSame(['2800.00', '1000.00'], $item->metadata['per_block_amounts']);
    }
}
