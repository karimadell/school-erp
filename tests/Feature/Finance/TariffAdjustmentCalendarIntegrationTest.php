<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentRefund;
use App\Models\ServiceCoverage;
use App\Models\StudentCredit;
use App\Models\TariffAdjustment;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\TariffAdjustmentService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finance V2, Phase 2D item 4 (docs/finance-v2-architecture.md).
 *
 * Confirms TariffAdjustmentService — unchanged in this phase — correctly
 * operates on ServiceCoverage rows automatically created by Phase 2D item 2
 * for a calendar-billed (Quick-Registration-shaped) invoice, exactly the
 * scenario the confirmed business rules describe: annual Transport
 * prepayment at an old tariff, a mid-year increase, an auditable debit for
 * only the affected future periods, original payments untouched.
 */
class TariffAdjustmentCalendarIntegrationTest extends FinanceOperationsTestCase
{
    private function transportFee(): Fee
    {
        $fee = Fee::create(['name_ru' => 'Трансфер (тариф)', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly',
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1500.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2026-12-31', 'is_active' => true,
        ]);

        return $fee;
    }

    private function issueAndFullyPay(Fee $fee): Invoice
    {
        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1']],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);

        // InvoicePaymentService::record() itself only settles ONE
        // installment per call (the §4 multi-installment walk lives in
        // QuickStudentRegistrationService specifically) — pay each of the
        // 10 generated monthly installments individually to fully prepay
        // the year, exactly what "annual prepayment" means here.
        foreach ($invoice->installments()->orderBy('sequence')->get() as $installment) {
            app(\App\Services\Finance\InvoicePaymentService::class)->record(
                invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: (string) $installment->amount,
                paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
                installmentId: $installment->id,
            );
        }

        return $invoice->fresh();
    }

    public function test_annual_transport_prepayment_then_mid_year_increase_produces_correct_debit_and_preserves_original_payment(): void
    {
        $fee = $this->transportFee();
        $invoice = $this->issueAndFullyPay($fee);
        $originalPayments = $invoice->payments->map->toArray()->all();
        $before = [InvoicePayment::count(), CashTransaction::count(), PaymentRefund::count()];

        // September 2026 through June 2027 = 10 monthly installments,
        // each the FULL 1500.00 unit tariff (P0 Blocker 1 corrective fix:
        // never total/count — invoice total is correctly 1500 x 10 = 15000).
        $this->assertSame(10, $invoice->installments()->count());
        $this->assertSame(10, count($originalPayments));
        $this->assertSame('15000.00', $invoice->total_amount);
        foreach ($invoice->installments as $installment) {
            $this->assertSame('1500.00', $installment->amount);
        }

        // Mid-year increase, effective January 1 — affects Jan-Jun (6 months).
        FeePrice::where('fee_id', $fee->id)->update(['end_date' => '2026-12-31']);
        $increase = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1700.00', 'currency' => 'EGP', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();
        $preview = app(TariffAdjustmentService::class)->preview($coverage->fresh(), $increase);
        $this->assertSame(['2027-01-01', '2027-06-30'], $preview['segment']);
        $this->assertSame(6, $preview['units']);
        $this->assertSame('200.00', $preview['difference_per_unit']);
        $this->assertSame('1200.00', $preview['total_difference']);

        $adjustment = app(TariffAdjustmentService::class)->approve($coverage->fresh(), $increase, $this->accountant);
        $this->assertSame('debit', $adjustment->kind);
        $this->assertSame('1200.00', $adjustment->total_difference);
        $this->assertSame(1, $adjustment->segments->count());
        $segment = $adjustment->segments->sole();
        $this->assertSame(6, $segment->units);
        $this->assertSame('1500.00', $segment->previous_unit_price);
        $this->assertSame('1700.00', $segment->new_unit_price);

        // ONE adjustment invoice in V1 for the total debit difference.
        $debitInvoice = Invoice::find($adjustment->posting_invoice_id);
        $this->assertSame('1200.00', $debitInvoice->total_amount);
        $this->assertSame(1, $debitInvoice->items->count());

        // Original prepayment/invoice/payments completely untouched — the
        // adjustment created exactly one NEW debit invoice, never rewrote
        // or added to the prepayment's own 10 payments.
        $this->assertSame(10, InvoicePayment::where('invoice_id', $invoice->id)->count(), 'the original invoice still has exactly its own 10 payments, no new one added to it');
        $this->assertSame($originalPayments, $invoice->fresh()->payments->map->toArray()->all());
        $this->assertSame(0, PaymentRefund::count(), 'no refund of any kind was created');
    }

    public function test_second_approve_call_for_overlapping_segment_is_rejected(): void
    {
        $fee = $this->transportFee();
        $this->issueAndFullyPay($fee);
        FeePrice::where('fee_id', $fee->id)->update(['end_date' => '2026-12-31']);
        $increase = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1700.00', 'currency' => 'EGP', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();
        app(TariffAdjustmentService::class)->approve($coverage->fresh(), $increase, $this->accountant);

        // Overlapping second increase within the same already-posted segment.
        $secondIncrease = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1800.00', 'currency' => 'EGP', 'start_date' => '2027-03-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        app(TariffAdjustmentService::class)->approve($coverage->fresh(), $secondIncrease, $this->accountant);
    }

    public function test_price_decrease_through_calendar_coverage_creates_credit_not_debit(): void
    {
        $fee = $this->transportFee();
        $invoice = $this->issueAndFullyPay($fee);
        $before = $invoice->payments->map->toArray()->all();

        FeePrice::where('fee_id', $fee->id)->update(['end_date' => '2026-12-31']);
        $decrease = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1300.00', 'currency' => 'EGP', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();

        $adjustment = app(TariffAdjustmentService::class)->approve($coverage->fresh(), $decrease, $this->accountant);

        $this->assertSame('credit', $adjustment->kind);
        $this->assertSame('-1200.00', $adjustment->total_difference);
        $this->assertNull($adjustment->posting_invoice_id, 'a decrease never posts a debit invoice');
        $credit = StudentCredit::sole();
        $this->assertSame('1200.00', $credit->original_amount);
        $this->assertSame(StudentCredit::STATUS_AVAILABLE, $credit->status);

        // Original payments still untouched.
        $this->assertSame($before, $invoice->fresh()->payments->map->toArray()->all());
    }

    public function test_adjustment_applies_to_invoiced_coverage_regardless_of_payment_status(): void
    {
        // Coverage is created at ISSUANCE time, independent of payment
        // status — an adjustment must apply to the Jan-Jun periods even
        // when only Sep-Dec has actually been paid so far.
        $fee = $this->transportFee();
        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1']],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);
        // Only pay the first 4 installments (Sep-Dec) — Jan-Jun remain
        // unpaid/outstanding.
        foreach ($invoice->installments()->orderBy('sequence')->limit(4)->get() as $installment) {
            app(\App\Services\Finance\InvoicePaymentService::class)->record(
                invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: (string) $installment->amount,
                paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
                installmentId: $installment->id,
            );
        }

        FeePrice::where('fee_id', $fee->id)->update(['end_date' => '2026-12-31']);
        $increase = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1700.00', 'currency' => 'EGP', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();

        $preview = app(TariffAdjustmentService::class)->preview($coverage->fresh(), $increase);
        $this->assertSame(6, $preview['units'], 'the full Jan-Jun delta applies, unaffected by only 4 of 10 installments being paid so far');
        $this->assertSame('1200.00', $preview['total_difference']);
    }

    public function test_successive_tariff_changes_each_apply_only_to_their_own_segment(): void
    {
        // Sep-Dec 1500, Jan-Feb 1700, Mar-May 1850 — three approved
        // adjustments in order, each correct and non-overlapping.
        $this->year->forceFill(['end_date' => '2027-05-31'])->save();
        $fee = Fee::create(['name_ru' => 'Трансфер (последовательные изменения)', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2026-12-31', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1']],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);
        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();

        $jan = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1700.00', 'currency' => 'EGP', 'start_date' => '2027-01-01', 'end_date' => '2027-02-28', 'is_active' => true]);
        $mar = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1850.00', 'currency' => 'EGP', 'start_date' => '2027-03-01', 'end_date' => '2027-05-31', 'is_active' => true]);

        $janAdjustment = app(TariffAdjustmentService::class)->approve($coverage->fresh(), $jan, $this->accountant);
        $janSegment = $janAdjustment->segments->sole();
        $this->assertSame('2027-01-01', $janSegment->segment_start->toDateString());
        $this->assertSame('2027-02-28', $janSegment->segment_end->toDateString());
        $this->assertSame(2, $janSegment->units);
        $this->assertSame('400.00', $janAdjustment->total_difference, '200 x 2 months');

        $marAdjustment = app(TariffAdjustmentService::class)->approve($coverage->fresh(), $mar, $this->accountant);
        $this->assertSame(3, $marAdjustment->segments->sole()->units);
        $this->assertSame('450.00', $marAdjustment->total_difference, '150 x 3 months (1850-1700=150)');

        $this->assertSame('850.00', bcadd($janAdjustment->total_difference, $marAdjustment->total_difference, 2));
        $this->assertSame(2, TariffAdjustment::count());

        // The existing overlap guard still rejects a genuinely conflicting
        // (different price, overlapping date window) re-application
        // anywhere in this sequence — re-approving the exact same $jan
        // price again would instead be an idempotent replay (already
        // covered by Phase2TariffAdjustmentAndPromiseTest), not this case.
        $conflictingFeb = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1750.00', 'currency' => 'EGP', 'start_date' => '2027-02-01', 'end_date' => '2027-02-28', 'is_active' => true]);
        $this->expectException(ValidationException::class);
        app(TariffAdjustmentService::class)->approve($coverage->fresh(), $conflictingFeb, $this->accountant);
    }

    public function test_effective_date_mid_month_and_last_day_both_apply_new_tariff_to_the_full_month(): void
    {
        // Verified against TariffAdjustmentService::preview()'s actual
        // logic: $start = coverage_start.max(newPrice.start_date) uses the
        // exact day, but the UNITS computation
        // ($start->startOfMonth()->diffInMonths($end->startOfMonth())+1)
        // normalizes both ends to calendar-month boundaries before
        // counting — so an effective_from anywhere within December still
        // counts December as exactly 1 full unit, never a fractional one.
        $fee = $this->transportFee();
        $this->issueAndFullyPay($fee);
        FeePrice::where('fee_id', $fee->id)->update(['end_date' => '2026-11-30']);
        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();

        $midMonth = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1700.00', 'currency' => 'EGP', 'start_date' => '2026-12-15', 'end_date' => '2026-12-31', 'is_active' => true]);
        $previewMid = app(TariffAdjustmentService::class)->preview($coverage->fresh(), $midMonth);
        $this->assertSame(1, $previewMid['units'], 'effective Dec 15 still counts the full December as 1 unit, not a fraction');
        $this->assertSame('200.00', $previewMid['total_difference']);
    }

    public function test_yearly_billed_periodic_fee_uses_a_monthly_adjustment_basis_not_the_yearly_package_price(): void
    {
        // Yearly FeePrice may be a discounted package price — never
        // derive a monthly-equivalent by dividing it. ServiceCoverage
        // must be created against a SEPARATE, explicitly-configured
        // monthly basis tariff for the same Fee/dimensions.
        $fee = Fee::create(['name_ru' => 'Трансфер (годовой пакет)', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'yearly']);
        $yearlyPackage = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'yearly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '14000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $monthlyBasis = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1600.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2026-12-31', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'yearly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1']],
            'payment_type' => 'calendar', 'billing_period' => 'yearly',
        ], $this->accountant);
        app(\App\Services\Finance\InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: (string) $invoice->total_amount,
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            installmentId: $invoice->installments()->sole()->id,
        );

        // Invoice itself charged the real yearly package price, untouched.
        $item = $invoice->items->sole();
        $this->assertSame('14000.00', $item->amount);
        $this->assertSame('monthly', $item->metadata['adjustment_basis_period']);
        $this->assertSame($monthlyBasis->id, $item->metadata['adjustment_basis_fee_price_id']);
        $this->assertSame('1600.00', $item->metadata['adjustment_basis_unit_amount']);

        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();
        $this->assertSame($monthlyBasis->id, $coverage->fee_price_id, 'coverage tariff is the monthly basis, never the yearly package price');
        $this->assertSame('monthly', $coverage->billing_unit);
        $this->assertSame('2026-09-01', $coverage->coverage_start->toDateString());
        $this->assertSame($this->year->end_date->toDateString(), $coverage->coverage_end->toDateString());

        // A Jan 1 increase (of the MONTHLY basis) applies Jan-Jun (6
        // months) using the basis price, completely independent of the
        // yearly package amount actually charged.
        $increaseBasis = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '1800.00', 'currency' => 'EGP', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $originalPayment = $invoice->payments->sole()->toArray();
        $adjustment = app(TariffAdjustmentService::class)->approve($coverage->fresh(), $increaseBasis, $this->accountant);

        $this->assertSame('debit', $adjustment->kind);
        $this->assertSame(bcmul('200.00', '6', 2), $adjustment->total_difference, '(1800-1600) x 6 months, using the basis price, not the 14000 package price');
        $this->assertSame($originalPayment, $invoice->fresh()->payments->sole()->toArray(), 'original yearly invoice/payment completely untouched');
    }

    public function test_yearly_periodic_fee_with_no_monthly_basis_blocks_issuance_completely(): void
    {
        $fee = Fee::create(['name_ru' => 'Трансфер (без базового тарифа)', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'yearly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'yearly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '14000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        // Deliberately no monthly-payment_period FeePrice for this Fee.

        $invoicesBefore = Invoice::count();

        try {
            app(InvoiceIssuanceService::class)->issue($this->student, [
                'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
                'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
                'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'yearly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1']],
                'payment_type' => 'calendar', 'billing_period' => 'yearly',
            ], $this->accountant);
            $this->fail('Expected ValidationException — no monthly basis tariff exists.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fees', $exception->errors());
        }

        $this->assertSame($invoicesBefore, Invoice::count());
        $this->assertSame(0, ServiceCoverage::where('fee_id', $fee->id)->count());
    }
}
