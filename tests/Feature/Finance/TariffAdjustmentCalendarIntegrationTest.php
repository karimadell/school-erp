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

        // September 2026 through June 2027 = 10 monthly installments at 150.00 each (1500/10).
        $this->assertSame(10, $invoice->installments()->count());
        $this->assertSame(10, count($originalPayments));

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
}
