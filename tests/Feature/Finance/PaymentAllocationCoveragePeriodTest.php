<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\InstallmentCoveragePeriod;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentAllocation;
use App\Models\PaymentAllocationCoveragePeriod;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\InvoicePaymentService;

/**
 * Finance V2, Phase 2D corrective pass #2 (P0 Blocker 2) —
 * payment-to-coverage-period allocation.
 *
 * PaymentAllocation (Phase 1A) already ties a payment to a specific
 * InvoiceItem — this file proves the one level of precision beyond that:
 * when a bundled invoice's single shared installment covers TWO Fees'
 * own coverage periods, an explicit per-item allocation must correctly
 * settle only the intended Fee's own period, never the other, and never
 * ambiguously "the whole shared installment."
 */
class PaymentAllocationCoveragePeriodTest extends FinanceOperationsTestCase
{
    private function bundledInvoice(): Invoice
    {
        $tuition = Fee::create(['name_ru' => 'Обучение (аллокация)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $tuition->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $tuition->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $transport = Fee::create(['name_ru' => 'Трансфер (аллокация)', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $transport->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $transport->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '400.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        return app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-17',
            'items' => [
                ['fee_id' => $tuition->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null],
                ['fee_id' => $transport->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1'],
            ],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);
    }

    private function pay(Invoice $invoice, string $amount, array $allocations): void
    {
        $installment = $invoice->installments()->orderBy('sequence')->first();
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: $amount,
            paymentMethod: 'cash', idempotencyKey: (string) \Illuminate\Support\Str::uuid(), actor: $this->accountant,
            installmentId: $installment->id, allocations: $allocations,
        );
    }

    private function firstPeriod(Invoice $invoice, InvoiceItem $item): InstallmentCoveragePeriod
    {
        $installment = $invoice->installments()->orderBy('sequence')->first();
        $coverage = \App\Models\ServiceCoverage::where('invoice_item_id', $item->id)->sole();

        return InstallmentCoveragePeriod::where('invoice_installment_id', $installment->id)
            ->where('service_coverage_id', $coverage->id)->sole();
    }

    // (A) pay 400 explicitly allocated to Transport only.
    public function test_a_payment_allocated_only_to_transport_settles_only_transports_own_period(): void
    {
        $invoice = $this->bundledInvoice();
        $tuitionItem = $invoice->items->first(fn ($i) => $i->fee->category === Fee::CATEGORY_TUITION);
        $transportItem = $invoice->items->first(fn ($i) => $i->fee->category === Fee::CATEGORY_TRANSPORT);

        $this->pay($invoice, '400.00', [['invoice_item_id' => $transportItem->id, 'amount' => '400.00']]);

        $transportPeriod = $this->firstPeriod($invoice, $transportItem);
        $tuitionPeriod = $this->firstPeriod($invoice, $tuitionItem);
        $this->assertSame('settled', $transportPeriod->settlementStatus());
        $this->assertSame('unpaid', $tuitionPeriod->settlementStatus());
        $this->assertSame('400.00', bcadd((string) $transportPeriod->paymentAllocationCoveragePeriods()->sum('amount'), '0', 2));
    }

    // (B) pay 1000 explicitly allocated to Tuition only.
    public function test_a_payment_allocated_only_to_tuition_settles_only_tuitions_own_period(): void
    {
        $invoice = $this->bundledInvoice();
        $tuitionItem = $invoice->items->first(fn ($i) => $i->fee->category === Fee::CATEGORY_TUITION);
        $transportItem = $invoice->items->first(fn ($i) => $i->fee->category === Fee::CATEGORY_TRANSPORT);

        $this->pay($invoice, '1000.00', [['invoice_item_id' => $tuitionItem->id, 'amount' => '1000.00']]);

        $this->assertSame('settled', $this->firstPeriod($invoice, $tuitionItem)->settlementStatus());
        $this->assertSame('unpaid', $this->firstPeriod($invoice, $transportItem)->settlementStatus());
    }

    // (C) pay 700 split explicitly across both — exact per-service partial state.
    public function test_a_split_payment_reflects_exact_partial_state_per_service(): void
    {
        $invoice = $this->bundledInvoice();
        $tuitionItem = $invoice->items->first(fn ($i) => $i->fee->category === Fee::CATEGORY_TUITION);
        $transportItem = $invoice->items->first(fn ($i) => $i->fee->category === Fee::CATEGORY_TRANSPORT);

        // Transport's own share (400) is fully covered; Tuition's own
        // share (1000) only gets 300 of it — a genuine partial state,
        // distinct per service despite sharing one installment/payment.
        $this->pay($invoice, '700.00', [
            ['invoice_item_id' => $transportItem->id, 'amount' => '400.00'],
            ['invoice_item_id' => $tuitionItem->id, 'amount' => '300.00'],
        ]);

        $this->assertSame('settled', $this->firstPeriod($invoice, $transportItem)->settlementStatus());
        $this->assertSame('partial', $this->firstPeriod($invoice, $tuitionItem)->settlementStatus());
        $this->assertSame('300.00', bcadd((string) $this->firstPeriod($invoice, $tuitionItem)->paymentAllocationCoveragePeriods()->sum('amount'), '0', 2));
    }

    // (D) full 1400 settlement — both fully settled.
    public function test_full_settlement_of_the_shared_installment_settles_both_services_own_periods(): void
    {
        $invoice = $this->bundledInvoice();
        $tuitionItem = $invoice->items->first(fn ($i) => $i->fee->category === Fee::CATEGORY_TUITION);
        $transportItem = $invoice->items->first(fn ($i) => $i->fee->category === Fee::CATEGORY_TRANSPORT);

        $this->pay($invoice, '1400.00', [
            ['invoice_item_id' => $tuitionItem->id, 'amount' => '1000.00'],
            ['invoice_item_id' => $transportItem->id, 'amount' => '400.00'],
        ]);

        $this->assertSame('settled', $this->firstPeriod($invoice, $tuitionItem)->settlementStatus());
        $this->assertSame('settled', $this->firstPeriod($invoice, $transportItem)->settlementStatus());
    }

    // (F) a historical/legacy unallocated payment reads as explicitly ambiguous.
    public function test_a_legacy_unallocated_payment_reads_as_unallocated_never_as_falsely_settling_a_specific_service(): void
    {
        $invoice = $this->bundledInvoice();
        $tuitionItem = $invoice->items->first(fn ($i) => $i->fee->category === Fee::CATEGORY_TUITION);
        $transportItem = $invoice->items->first(fn ($i) => $i->fee->category === Fee::CATEGORY_TRANSPORT);
        $installment = $invoice->installments()->orderBy('sequence')->first();

        // Simulates a pre-Phase-1A / ambiguous-multi-item legacy payment:
        // an InvoicePayment against this installment with NO
        // PaymentAllocation rows at all (bypassing record()'s own
        // allocation-clean rejection, exactly the way a real historical
        // row would already exist before that rule was introduced).
        \App\Models\InvoicePayment::create([
            'invoice_id' => $invoice->id, 'invoice_installment_id' => $installment->id,
            'cash_account_id' => $this->cash->id, 'amount' => '1400.00', 'payment_method' => 'cash',
            'paid_at' => now(), 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_hash' => hash('sha256', 'legacy-test'),
        ]);

        $this->assertSame(0, PaymentAllocation::count(), 'a genuine legacy payment has no allocation rows at all');
        $this->assertSame('unallocated', $this->firstPeriod($invoice, $tuitionItem)->settlementStatus());
        $this->assertSame('unallocated', $this->firstPeriod($invoice, $transportItem)->settlementStatus());
        $this->assertSame(0, PaymentAllocationCoveragePeriod::count(), 'never fabricate a period-level allocation for an unallocated legacy payment');
    }

    public function test_a_calendar_billed_installment_coverage_period_carries_its_own_full_amount(): void
    {
        $invoice = $this->bundledInvoice();
        $tuitionItem = $invoice->items->first(fn ($i) => $i->fee->category === Fee::CATEGORY_TUITION);
        $transportItem = $invoice->items->first(fn ($i) => $i->fee->category === Fee::CATEGORY_TRANSPORT);

        $this->assertSame('1000.00', (string) $this->firstPeriod($invoice, $tuitionItem)->amount);
        $this->assertSame('400.00', (string) $this->firstPeriod($invoice, $transportItem)->amount);
    }
}
