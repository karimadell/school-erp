<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\PaymentRefundAllocation;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use App\Services\Finance\PaymentAllocationAnalyzer;
use App\Services\Finance\PaymentAllocationStatus;
use Illuminate\Support\Str;

/**
 * Finance V2, Phase 2A (docs/finance-v2-architecture.md).
 *
 * Unit-level coverage of the PURE, stateless PaymentAllocationAnalyzer,
 * extracted from InvoicePaymentService::analyzeAllocations() without
 * changing its behavior. Every scenario here also has an equivalent
 * assertion against InvoicePaymentService's own public surface
 * (isAllocationClean()/remainingAllocatableByItem()) elsewhere in the suite
 * (see FinanceV2Phase1ERefundCleanAllocationTest etc.) — case #10 below
 * additionally asserts direct parity between the two, in the same test run,
 * so a regression in the extraction cannot silently diverge from the
 * original write-time behavior.
 */
class PaymentAllocationAnalyzerTest extends FinanceOperationsTestCase
{
    private function analyzer(): PaymentAllocationAnalyzer
    {
        return app(PaymentAllocationAnalyzer::class);
    }

    private function payments(): InvoicePaymentService
    {
        return app(InvoicePaymentService::class);
    }

    private function refunds(): InvoiceRefundService
    {
        return app(InvoiceRefundService::class);
    }

    private function secondInvoiceItem(Invoice $invoice, string $amount): InvoiceItem
    {
        return InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'fee_id' => $this->fee->id,
            'description' => 'Вторая строка (Phase 2A analyzer test)',
            'unit_price' => $amount,
            'quantity' => 1,
            'amount' => $amount,
            'paid_amount' => '0.00',
            'remaining_amount' => $amount,
        ]);
    }

    /**
     * Mirrors InvoicePaymentService's own PLAIN (forUpdate: false) read
     * shape for analyzeAllocations()'s inputs — the same shape the Phase 2A
     * Collections read model will use.
     */
    private function fetchInvoiceInputs(Invoice $invoice): array
    {
        $items = $invoice->items()->get();
        $itemIds = $items->pluck('id');

        return [
            $items,
            InvoicePayment::query()->where('invoice_id', $invoice->id)->get(),
            PaymentAllocation::query()->whereIn('invoice_item_id', $itemIds)->get(),
            PaymentRefund::query()->where('invoice_id', $invoice->id)->with('allocations')->get(),
        ];
    }

    // ----- 1: fully allocated single-service payment = clean ---------------

    public function test_fully_allocated_single_service_payment_is_fully_attributed(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $status = $this->analyzer()->classifyPayment($payment, $payment->allocations, collect());

        $this->assertSame(PaymentAllocationStatus::FullyAttributed, $status);
    }

    // ----- 2: fully allocated multi-service payment = clean ----------------

    public function test_fully_allocated_multi_service_payment_is_fully_attributed(): void
    {
        $invoice = $this->invoice('1000.00');
        $tuition = $invoice->items->sole();
        $uniform = $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);

        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1500.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [
                ['invoice_item_id' => $tuition->id, 'amount' => '1000.00'],
                ['invoice_item_id' => $uniform->id, 'amount' => '500.00'],
            ],
        );

        $status = $this->analyzer()->classifyPayment($payment, $payment->allocations, collect());

        $this->assertSame(PaymentAllocationStatus::FullyAttributed, $status);
    }

    // ----- 3: zero payment allocations = UNALLOCATED ------------------------

    public function test_zero_allocation_payment_is_unallocated(): void
    {
        $invoice = $this->invoice('1000.00');
        $uniform = $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);

        // Historical zero-allocation payment — record() itself refuses this
        // shape against a multi-item invoice; simulated directly (same
        // fixture style as FinanceV2Phase1ERefundCleanAllocationTest).
        $legacyPayment = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '500.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);

        $status = $this->analyzer()->classifyPayment($legacyPayment, collect(), collect());

        $this->assertSame(PaymentAllocationStatus::Unallocated, $status);
    }

    // ----- 4: partial allocation coverage = NEEDS_REVIEW --------------------

    public function test_partial_allocation_coverage_needs_review(): void
    {
        $invoice = $this->invoice('1000.00');
        $tuition = $invoice->items->sole();

        $payment = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '1000.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        PaymentAllocation::create(['invoice_payment_id' => $payment->id, 'invoice_item_id' => $tuition->id, 'amount' => '600.00']);

        $status = $this->analyzer()->classifyPayment($payment, $payment->allocations()->get(), collect());

        $this->assertSame(PaymentAllocationStatus::NeedsReview, $status);
    }

    // ----- 5: fully attributed refund does not make the payment ambiguous --

    public function test_fully_attributed_refund_keeps_payment_fully_attributed(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '300.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $payment->refresh();
        $refundAllocationsAgainstOwnAllocations = $payment->allocations->flatMap->refundAllocations;
        $status = $this->analyzer()->classifyPayment($payment, $payment->allocations, $refundAllocationsAgainstOwnAllocations);

        $this->assertSame(PaymentAllocationStatus::FullyAttributed, $status, 'a fully-attributed refund does not poison the payment');
    }

    // ----- 6: zero refund allocation on historical refund -------------------

    public function test_zero_allocation_refund_is_unallocated(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $refund = PaymentRefund::create([
            'invoice_payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'student_id' => $this->student->id,
            'cash_account_id' => $this->cash->id, 'amount' => '100.00', 'currency' => 'EGP',
            'reason' => 'Legacy unattributed refund (test fixture)', 'refunded_at' => now(),
            'created_by' => $this->accountant->id, 'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', Str::random()),
        ]);

        $status = $this->analyzer()->classifyRefund($refund, $refund->allocations()->get(), $payment->allocations->keyBy('id'));

        $this->assertSame(PaymentAllocationStatus::Unallocated, $status);
    }

    // ----- 7: partial refund allocation = NEEDS_REVIEW -----------------------

    public function test_partial_refund_allocation_needs_review(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $allocation = $payment->allocations->sole();

        $refund = PaymentRefund::create([
            'invoice_payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'student_id' => $this->student->id,
            'cash_account_id' => $this->cash->id, 'amount' => '100.00', 'currency' => 'EGP',
            'reason' => 'Anomalous partial refund-allocation coverage (test fixture)', 'refunded_at' => now(),
            'created_by' => $this->accountant->id, 'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        PaymentRefundAllocation::create(['payment_refund_id' => $refund->id, 'payment_allocation_id' => $allocation->id, 'amount' => '40.00']);

        $status = $this->analyzer()->classifyRefund($refund, $refund->allocations()->get(), $payment->allocations->keyBy('id'));

        $this->assertSame(PaymentAllocationStatus::NeedsReview, $status);
    }

    // ----- 8: refund allocation linked to the wrong payment ------------------

    public function test_refund_allocation_referencing_a_foreign_payment_needs_review(): void
    {
        $invoice = $this->invoice('1200.00');
        $paymentOne = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1200.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $allocationOne = $paymentOne->allocations->sole();

        $invoiceTwo = $this->invoice('800.00');
        $paymentTwo = $this->payments()->record(
            invoiceId: $invoiceTwo->id, cashAccountId: $this->cash->id, amount: '800.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        // A refund on paymentTwo whose allocation corruptly references
        // paymentOne's allocation — never producible through
        // InvoiceRefundService.
        $refund = PaymentRefund::create([
            'invoice_payment_id' => $paymentTwo->id, 'invoice_id' => $invoiceTwo->id, 'student_id' => $this->student->id,
            'cash_account_id' => $this->cash->id, 'amount' => '100.00', 'currency' => 'EGP',
            'reason' => 'Cross-payment corruption (test fixture)', 'refunded_at' => now(),
            'created_by' => $this->accountant->id, 'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        PaymentRefundAllocation::create(['payment_refund_id' => $refund->id, 'payment_allocation_id' => $allocationOne->id, 'amount' => '100.00']);

        $status = $this->analyzer()->classifyRefund($refund, $refund->allocations()->get(), $paymentTwo->allocations->keyBy('id'));

        $this->assertSame(PaymentAllocationStatus::NeedsReview, $status);
    }

    // ----- 9: excess cumulative refund allocation on the PAYMENT side -------

    public function test_cumulative_refund_allocation_exceeding_original_allocation_needs_review(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $allocation = $payment->allocations->sole();

        // Two individually-fully-covered refunds whose combined effect on
        // the ONE allocation exceeds its own amount — never producible
        // through InvoiceRefundService's own per-allocation cap in a single
        // call chain; written directly, exactly like
        // FinanceV2Phase1ERefundCleanAllocationTest's equivalent invoice-level case.
        $refundA = PaymentRefund::create([
            'invoice_payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'student_id' => $this->student->id,
            'cash_account_id' => $this->cash->id, 'amount' => '600.00', 'currency' => 'EGP',
            'reason' => 'a', 'refunded_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        PaymentRefundAllocation::create(['payment_refund_id' => $refundA->id, 'payment_allocation_id' => $allocation->id, 'amount' => '600.00']);
        $refundB = PaymentRefund::create([
            'invoice_payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'student_id' => $this->student->id,
            'cash_account_id' => $this->cash->id, 'amount' => '500.00', 'currency' => 'EGP',
            'reason' => 'b', 'refunded_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        // 600.00 + 500.00 = 1100.00 against an allocation of only 1000.00.
        PaymentRefundAllocation::create(['payment_refund_id' => $refundB->id, 'payment_allocation_id' => $allocation->id, 'amount' => '500.00']);

        $payment->refresh();
        $refundAllocationsAgainstOwnAllocations = $payment->allocations->flatMap->refundAllocations;
        $status = $this->analyzer()->classifyPayment($payment, $payment->allocations, $refundAllocationsAgainstOwnAllocations);

        $this->assertSame(PaymentAllocationStatus::NeedsReview, $status, 'cumulative refund exceeds the allocation it targets');
    }

    // ----- 10: analyzer/service parity ---------------------------------------

    public function test_analyzer_invoice_level_result_matches_invoice_payment_service_for_a_clean_invoice(): void
    {
        $invoice = $this->invoice('1000.00');
        $tuition = $invoice->items->sole();
        $uniform = $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);
        $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1500.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [
                ['invoice_item_id' => $tuition->id, 'amount' => '1000.00'],
                ['invoice_item_id' => $uniform->id, 'amount' => '500.00'],
            ],
        );
        $invoice->refresh();

        [$items, $payments, $allocations, $refunds] = $this->fetchInvoiceInputs($invoice);
        $result = $this->analyzer()->analyzeInvoice($items, $payments, $allocations, $refunds);

        $this->assertTrue($result['clean']);
        $this->assertTrue($this->payments()->isAllocationClean($invoice), 'InvoicePaymentService::isAllocationClean() agrees');
        $this->assertEquals(
            $this->payments()->remainingAllocatableByItem($invoice)->sort(),
            $result['netByItem']->map(fn ($net, $itemId) => bcsub((string) $items->firstWhere('id', $itemId)->amount, $net, 2))->sort(),
            'netByItem, converted to remaining-allocatable the same way remainingAllocatableByItem() does, matches exactly'
        );
    }

    public function test_analyzer_invoice_level_result_matches_invoice_payment_service_for_an_ambiguous_invoice(): void
    {
        $invoice = $this->invoice('1000.00');
        $uniform = $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);

        // Historical zero-allocation payment — poisons the whole invoice.
        InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '500.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        $invoice->refresh();

        [$items, $payments, $allocations, $refunds] = $this->fetchInvoiceInputs($invoice);
        $result = $this->analyzer()->analyzeInvoice($items, $payments, $allocations, $refunds);

        $this->assertFalse($result['clean']);
        $this->assertFalse($this->payments()->isAllocationClean($invoice), 'InvoicePaymentService::isAllocationClean() agrees');
    }

    public function test_classifier_parity_between_read_path_and_write_path_for_same_persisted_data(): void
    {
        // Same fixed, already-persisted data fed to both the write-side
        // (InvoicePaymentService, via a fresh plain read) and the
        // Collections-page read path — must always agree, since both
        // ultimately call the exact same PaymentAllocationAnalyzer methods.
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $writeSideClean = $this->payments()->isAllocationClean($invoice->fresh());

        $payment->refresh();
        $readSideStatus = $this->analyzer()->classifyPayment($payment, $payment->allocations, collect());

        $this->assertTrue($writeSideClean);
        $this->assertSame(PaymentAllocationStatus::FullyAttributed, $readSideStatus);
    }
}
