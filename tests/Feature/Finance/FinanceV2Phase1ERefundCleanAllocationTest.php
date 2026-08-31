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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finance V2, Phase 1E (docs/finance-v2-architecture.md §19 Phase 1E).
 *
 * Refund-aware allocation cleanliness. Phase 1D made NEW refunds
 * attributable to their original PaymentAllocation via
 * PaymentRefundAllocation. Phase 1E replaces Phase 1C's blunt "any refund
 * ⇒ ambiguous" rule with a precise per-payment/per-refund invariant
 * (InvoicePaymentService::analyzeAllocations()): an invoice with fully
 * attributed payments AND fully attributed refunds stays allocation-clean,
 * reopening exactly the refunded capacity of each InvoiceItem for a later
 * payment — while ANY genuinely ambiguous or anomalous event anywhere on
 * the invoice still poisons the WHOLE invoice, exactly as before.
 */
class FinanceV2Phase1ERefundCleanAllocationTest extends FinanceOperationsTestCase
{
    private function payments(): InvoicePaymentService
    {
        return app(InvoicePaymentService::class);
    }

    private function refunds(): InvoiceRefundService
    {
        return app(InvoiceRefundService::class);
    }

    private function secondInvoiceItem(Invoice $invoice, string $amount, bool $nonRefundable = false): InvoiceItem
    {
        return InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'fee_id' => $this->fee->id,
            'description' => 'Вторая строка (Phase 1E test)',
            'unit_price' => $amount,
            'quantity' => 1,
            'amount' => $amount,
            'paid_amount' => '0.00',
            'remaining_amount' => $amount,
            'is_non_refundable' => $nonRefundable,
        ]);
    }

    /**
     * The prompt's own worked example: Tuition 1000 + Uniform 500, a single
     * 1500 payment split fully across both, then 400 refunded from Tuition
     * only (an explicit split, since the payment has two allocations and
     * 400 does not exactly exhaust the eligible pool).
     *
     * @return array{0: Invoice, 1: InvoiceItem, 2: InvoiceItem, 3: InvoicePayment}
     */
    private function tuitionAndUniformFullyPaidThenTuitionPartiallyRefunded(): array
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
        $tuitionAllocation = $payment->allocations->firstWhere('invoice_item_id', $tuition->id);

        $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '400.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['payment_allocation_id' => $tuitionAllocation->id, 'amount' => '400.00']],
        );

        return [$invoice->fresh(), $tuition, $uniform, $payment];
    }

    // ----- 1-4, 6: core clean-after-attributed-refund behavior --------------

    public function test_fully_allocated_multi_item_invoice_with_fully_attributed_refund_remains_clean(): void
    {
        [$invoice] = $this->tuitionAndUniformFullyPaidThenTuitionPartiallyRefunded();

        $this->assertTrue($this->payments()->isAllocationClean($invoice));
    }

    public function test_later_repayment_after_attributed_refund_succeeds(): void
    {
        [$invoice, $tuition] = $this->tuitionAndUniformFullyPaidThenTuitionPartiallyRefunded();

        $repayment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['invoice_item_id' => $tuition->id, 'amount' => '400.00']],
        );

        $this->assertSame('400.00', (string) $repayment->amount);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('0.00', (string) $invoice->fresh()->remaining_amount);
    }

    public function test_repayment_creates_payment_allocation_on_the_exact_refunded_item(): void
    {
        [$invoice, $tuition, $uniform] = $this->tuitionAndUniformFullyPaidThenTuitionPartiallyRefunded();

        $repayment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['invoice_item_id' => $tuition->id, 'amount' => '400.00']],
        );

        $allocation = $repayment->allocations->sole();
        $this->assertSame($tuition->id, $allocation->invoice_item_id);
        $this->assertNotSame($uniform->id, $allocation->invoice_item_id);
        $this->assertSame('400.00', (string) $allocation->amount);
    }

    public function test_refunded_item_capacity_reopens_by_exactly_the_refund_allocation_amount(): void
    {
        [$invoice, $tuition] = $this->tuitionAndUniformFullyPaidThenTuitionPartiallyRefunded();

        $remaining = $this->payments()->remainingAllocatableByItem($invoice);

        $this->assertSame('400.00', (string) $remaining->get($tuition->id));
    }

    public function test_non_refunded_items_capacity_remains_zero(): void
    {
        [$invoice, , $uniform] = $this->tuitionAndUniformFullyPaidThenTuitionPartiallyRefunded();

        $remaining = $this->payments()->remainingAllocatableByItem($invoice);

        $this->assertSame('0.00', (string) $remaining->get($uniform->id));
    }

    // ----- 5: per-item cap distinct from the overall invoice cap ------------

    /**
     * Isolates the PER-ITEM reopened-capacity cap from the overall
     * invoice-remaining cap: Tuition is paid alone first (Uniform left
     * completely uncollected, so it contributes real slack to the
     * invoice-wide remaining balance), then 300.00 of Tuition's payment is
     * refunded (single-allocation payment — Phase 1D auto-attributes it).
     * Tuition's own reopened capacity is exactly 300.00, but the
     * invoice-wide remaining balance is 800.00 (300.00 Tuition + 500.00
     * never-collected Uniform) — so a 310.00 request against Tuition alone
     * fits comfortably under the invoice-wide cap yet must still be
     * rejected by Tuition's own per-item cap.
     */
    public function test_attempting_to_repay_more_than_the_reopened_item_capacity_is_rejected_atomically(): void
    {
        $invoice = $this->invoice('1000.00');
        $tuition = $invoice->items->sole();
        $uniform = $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);

        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['invoice_item_id' => $tuition->id, 'amount' => '1000.00']],
        );
        $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '300.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $this->assertTrue($this->payments()->isAllocationClean($invoice->fresh()));
        $this->assertSame('300.00', (string) $this->payments()->remainingAllocatableByItem($invoice->fresh())->get($tuition->id));
        $balanceBefore = (string) $this->cash->fresh()->balance;

        try {
            $this->payments()->record(
                invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '310.00',
                paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
                allocations: [['invoice_item_id' => $tuition->id, 'amount' => '310.00']],
            );
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
        }

        $this->assertSame(1, InvoicePayment::where('invoice_id', $invoice->id)->count(), 'only the original payment exists');
        $this->assertSame($balanceBefore, (string) $this->cash->fresh()->balance, 'nothing was collected');
        $this->assertNotSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(0, PaymentAllocation::where('invoice_item_id', $uniform->id)->count());
    }

    // ----- 7-8: split refund reopens each item independently ----------------

    public function test_refund_split_across_two_items_reopens_each_exact_amount(): void
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
        $tuitionAllocation = $payment->allocations->firstWhere('invoice_item_id', $tuition->id);
        $uniformAllocation = $payment->allocations->firstWhere('invoice_item_id', $uniform->id);

        $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '400.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [
                ['payment_allocation_id' => $tuitionAllocation->id, 'amount' => '200.00'],
                ['payment_allocation_id' => $uniformAllocation->id, 'amount' => '200.00'],
            ],
        );

        $this->assertTrue($this->payments()->isAllocationClean($invoice->fresh()));
        $remaining = $this->payments()->remainingAllocatableByItem($invoice->fresh());
        $this->assertSame('200.00', (string) $remaining->get($tuition->id));
        $this->assertSame('200.00', (string) $remaining->get($uniform->id));
    }

    public function test_later_payment_split_across_those_two_items_succeeds_exactly(): void
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
        $tuitionAllocation = $payment->allocations->firstWhere('invoice_item_id', $tuition->id);
        $uniformAllocation = $payment->allocations->firstWhere('invoice_item_id', $uniform->id);
        $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '400.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [
                ['payment_allocation_id' => $tuitionAllocation->id, 'amount' => '200.00'],
                ['payment_allocation_id' => $uniformAllocation->id, 'amount' => '200.00'],
            ],
        );

        $repayment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [
                ['invoice_item_id' => $tuition->id, 'amount' => '200.00'],
                ['invoice_item_id' => $uniform->id, 'amount' => '200.00'],
            ],
        );

        $this->assertCount(2, $repayment->allocations);
        $this->assertSame('200.00', (string) $repayment->allocations->firstWhere('invoice_item_id', $tuition->id)->amount);
        $this->assertSame('200.00', (string) $repayment->allocations->firstWhere('invoice_item_id', $uniform->id)->amount);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('0.00', (string) $invoice->fresh()->remaining_amount);
    }

    // ----- 9-13: historical/partial ambiguity still poisons the invoice ----

    public function test_historical_refund_with_zero_refund_allocation_rows_keeps_invoice_ambiguous(): void
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
        $transaction = CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'created_by' => $this->accountant->id, 'amount' => '100.00',
            'type' => CashTransaction::TYPE_OUT, 'category' => CashTransaction::CATEGORY_REFUND,
            'description' => 'Legacy unattributed refund (test fixture)',
        ]);
        $refund->forceFill(['cash_transaction_id' => $transaction->id])->save();
        $invoice->refreshPaymentStatus();
        $this->assertCount(0, $refund->allocations, 'zero PaymentRefundAllocation rows, by construction');

        $this->assertFalse($this->payments()->isAllocationClean($invoice->fresh()));
    }

    public function test_historical_payment_with_zero_payment_allocation_rows_keeps_invoice_ambiguous(): void
    {
        $invoice = $this->invoice('1000.00');
        $uniform = $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);

        // A genuinely historical payment with no PaymentAllocation rows,
        // written directly — record() itself refuses to produce this shape
        // against a multi-item invoice (Phase 1C).
        $legacyPayment = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '500.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'created_by' => $this->accountant->id,
            'invoice_payment_id' => $legacyPayment->id, 'amount' => '500.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Legacy pre-Phase-1 payment (test fixture)',
        ]);
        $invoice->refreshPaymentStatus();

        $this->assertFalse($this->payments()->isAllocationClean($invoice->fresh()));
    }

    public function test_partial_payment_allocation_coverage_keeps_invoice_ambiguous(): void
    {
        $invoice = $this->invoice('1000.00');
        $tuition = $invoice->items->sole();

        // A payment whose own PaymentAllocation coverage is neither zero
        // nor full — never producible through record() (validateAllocations
        // enforces sum === amount), simulated directly.
        $payment = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '1000.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        PaymentAllocation::create(['invoice_payment_id' => $payment->id, 'invoice_item_id' => $tuition->id, 'amount' => '600.00']);
        CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'created_by' => $this->accountant->id,
            'invoice_payment_id' => $payment->id, 'amount' => '1000.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Anomalous partial-coverage payment (test fixture)',
        ]);
        $invoice->forceFill(['paid_amount' => '1000.00', 'remaining_amount' => '0.00', 'status' => Invoice::STATUS_PAID])->save();

        $this->assertFalse($this->payments()->isAllocationClean($invoice->fresh()));
    }

    public function test_partial_refund_allocation_coverage_keeps_invoice_ambiguous(): void
    {
        [$invoice, $tuition, , $payment] = $this->tuitionAndUniformFullyPaidThenTuitionPartiallyRefunded();
        $tuitionAllocation = $payment->allocations->firstWhere('invoice_item_id', $tuition->id);

        // A SECOND refund against the same payment, whose own
        // PaymentRefundAllocation coverage is neither zero nor full — never
        // producible through InvoiceRefundService::refund(), simulated
        // directly.
        $refund = PaymentRefund::create([
            'invoice_payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'student_id' => $this->student->id,
            'cash_account_id' => $this->cash->id, 'amount' => '100.00', 'currency' => 'EGP',
            'reason' => 'Anomalous partial refund-allocation coverage (test fixture)', 'refunded_at' => now(),
            'created_by' => $this->accountant->id, 'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        PaymentRefundAllocation::create(['payment_refund_id' => $refund->id, 'payment_allocation_id' => $tuitionAllocation->id, 'amount' => '40.00']);
        $transaction = CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'created_by' => $this->accountant->id, 'amount' => '100.00',
            'type' => CashTransaction::TYPE_OUT, 'category' => CashTransaction::CATEGORY_REFUND,
            'description' => 'Anomalous partial refund-allocation coverage (test fixture)',
        ]);
        $refund->forceFill(['cash_transaction_id' => $transaction->id])->save();
        $invoice->refreshPaymentStatus();

        $this->assertFalse($this->payments()->isAllocationClean($invoice->fresh()));
    }

    public function test_one_clean_event_plus_one_ambiguous_event_poisons_the_whole_invoice(): void
    {
        $invoice = $this->invoice('1000.00');
        $tuition = $invoice->items->sole();
        $uniform = $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);

        // Tuition: cleanly paid and cleanly, fully attributed refund.
        $tuitionPayment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['invoice_item_id' => $tuition->id, 'amount' => '1000.00']],
        );
        $this->refunds()->refund(
            invoicePaymentId: $tuitionPayment->id, amount: '100.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $this->assertTrue($this->payments()->isAllocationClean($invoice->fresh()), 'clean so far — one attributed refund only');

        // Uniform: a genuinely historical, zero-allocation payment — the one
        // ambiguous event.
        $legacyUniformPayment = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '500.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'created_by' => $this->accountant->id,
            'invoice_payment_id' => $legacyUniformPayment->id, 'amount' => '500.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Legacy pre-Phase-1 payment (test fixture)',
        ]);
        $invoice->refreshPaymentStatus();

        $this->assertFalse($this->payments()->isAllocationClean($invoice->fresh()), 'the one ambiguous event poisons the WHOLE invoice, including the otherwise-clean Tuition side');
    }

    // ----- 14: multiple fully attributed refunds remain clean ---------------

    public function test_multiple_fully_attributed_refunds_remain_clean(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1200.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '300.00', reason: 'a', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '200.00', reason: 'b', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $this->assertSame(2, PaymentRefund::count());
        $this->assertTrue($this->payments()->isAllocationClean($invoice->fresh()));
        // net = 1200.00 - 300.00 - 200.00 = 700.00; remaining = 1200.00 - 700.00 = 500.00.
        $this->assertSame('500.00', (string) $this->payments()->remainingAllocatableByItem($invoice->fresh())->get($invoice->items->sole()->id));
    }

    // ----- 15-20: defensive corruption checks -------------------------------

    public function test_cross_payment_refund_allocation_makes_invoice_ambiguous(): void
    {
        $invoice = $this->invoice('1200.00');
        $paymentOne = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1200.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $allocationOne = $paymentOne->allocations->sole();

        // A second, independent, otherwise-clean invoice/payment.
        $invoiceTwo = $this->invoice('800.00');
        $paymentTwo = $this->payments()->record(
            invoiceId: $invoiceTwo->id, cashAccountId: $this->cash->id, amount: '800.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        // A refund on paymentTwo whose PaymentRefundAllocation corruptly
        // references paymentOne's allocation instead — never producible
        // through InvoiceRefundService (it sources candidates strictly from
        // the refunded payment's own allocations).
        $refund = PaymentRefund::create([
            'invoice_payment_id' => $paymentTwo->id, 'invoice_id' => $invoiceTwo->id, 'student_id' => $this->student->id,
            'cash_account_id' => $this->cash->id, 'amount' => '100.00', 'currency' => 'EGP',
            'reason' => 'Cross-payment corruption (test fixture)', 'refunded_at' => now(),
            'created_by' => $this->accountant->id, 'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        PaymentRefundAllocation::create(['payment_refund_id' => $refund->id, 'payment_allocation_id' => $allocationOne->id, 'amount' => '100.00']);
        $transaction = CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'created_by' => $this->accountant->id, 'amount' => '100.00',
            'type' => CashTransaction::TYPE_OUT, 'category' => CashTransaction::CATEGORY_REFUND,
            'description' => 'Cross-payment corruption (test fixture)',
        ]);
        $refund->forceFill(['cash_transaction_id' => $transaction->id])->save();
        $invoiceTwo->refreshPaymentStatus();

        $this->assertFalse($this->payments()->isAllocationClean($invoiceTwo->fresh()), 'invoiceTwo is poisoned by its own refund pointing at a foreign allocation');
        $this->assertTrue($this->payments()->isAllocationClean($invoice->fresh()), 'invoiceOne (the target of the corrupt reference) is untouched — the corruption lives on invoiceTwo\'s own refund row');
    }

    public function test_cumulative_refund_allocation_exceeding_original_payment_allocation_makes_invoice_ambiguous(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $allocation = $payment->allocations->sole();

        // Two refunds whose PaymentRefundAllocation rows, taken together,
        // refund more than the allocation itself ever received — each
        // refund is individually fully-covered (so passes the per-refund
        // coverage check), but their cumulative effect on the one
        // allocation is corrupt. Written directly: InvoiceRefundService's
        // own per-allocation remaining-capacity check would reject the
        // second of these as a normal call.
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

        $this->assertFalse($this->payments()->isAllocationClean($invoice->fresh()));
    }

    /**
     * Compensating payment corruption — payment A over-allocated (1100.00
     * against its own 1000.00 amount), payment B under-allocated (900.00
     * against its own 1000.00 amount). Invoice-wide totals happen to
     * match exactly (2000.00 paid, 2000.00 allocated), which is precisely
     * what Phase 1B/1C's original invoice-wide aggregate comparison could
     * not have caught — Phase 1E's PER-PAYMENT check catches both
     * individually.
     */
    public function test_compensating_payment_corruption_across_two_payments_keeps_invoice_ambiguous(): void
    {
        $invoice = $this->invoice('1000.00');
        $tuition = $invoice->items->sole();
        $uniform = $this->secondInvoiceItem($invoice, '1000.00');
        $invoice->update(['total_amount' => '2000.00', 'remaining_amount' => '2000.00']);

        $paymentA = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '1000.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        PaymentAllocation::create(['invoice_payment_id' => $paymentA->id, 'invoice_item_id' => $tuition->id, 'amount' => '1100.00']);
        $paymentB = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '1000.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        PaymentAllocation::create(['invoice_payment_id' => $paymentB->id, 'invoice_item_id' => $uniform->id, 'amount' => '900.00']);

        $this->assertSame('2000.00', bcadd((string) InvoicePayment::where('invoice_id', $invoice->id)->sum('amount'), '0', 2));
        $this->assertSame('2000.00', bcadd((string) PaymentAllocation::whereIn('invoice_item_id', [$tuition->id, $uniform->id])->sum('amount'), '0', 2), 'invoice-wide totals coincidentally match — the old aggregate check would have missed this');

        $this->assertFalse($this->payments()->isAllocationClean($invoice->fresh()));
    }

    public function test_payment_allocation_referencing_an_invoice_item_from_another_invoice_makes_invoice_ambiguous(): void
    {
        $invoiceOne = $this->invoice('500.00');
        $itemOne = $invoiceOne->items->sole();
        $invoiceTwo = $this->invoice('700.00');
        $itemTwo = $invoiceTwo->items->sole();

        // A payment on invoiceOne whose sole allocation corruptly points at
        // invoiceTwo's item — never producible through record() (which only
        // ever offers this invoice's own items).
        $payment = InvoicePayment::create([
            'invoice_id' => $invoiceOne->id, 'cash_account_id' => $this->cash->id, 'amount' => '500.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        PaymentAllocation::create(['invoice_payment_id' => $payment->id, 'invoice_item_id' => $itemTwo->id, 'amount' => '500.00']);

        $this->assertFalse($this->payments()->isAllocationClean($invoiceOne->fresh()));
        $this->assertNotSame($itemOne->id, $itemTwo->id);
    }

    /**
     * Same underlying corruption as the cumulative-refund-exceeds-allocation
     * case above (a single PaymentAllocation refunded beyond its own
     * capacity), inspected from the "net per item cannot go negative" angle
     * specifically — analyzeAllocations()'s final per-item bounds check is
     * what a per-allocation-only check could still miss if it were ever
     * removed, so both are asserted independently as defense in depth.
     */
    public function test_net_allocated_to_item_going_negative_makes_invoice_ambiguous(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $allocation = $payment->allocations->sole();

        $refund = PaymentRefund::create([
            'invoice_payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'student_id' => $this->student->id,
            'cash_account_id' => $this->cash->id, 'amount' => '1200.00', 'currency' => 'EGP',
            'reason' => 'test', 'refunded_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        // Refunds more than the allocation's own 1000.00 amount.
        PaymentRefundAllocation::create(['payment_refund_id' => $refund->id, 'payment_allocation_id' => $allocation->id, 'amount' => '1200.00']);

        $this->assertFalse($this->payments()->isAllocationClean($invoice->fresh()));
    }

    /**
     * Genuinely distinct from the per-allocation-capacity checks above: two
     * SEPARATE, individually-fully-covered payments both allocate their
     * full amount to the SAME InvoiceItem, so the item's own gross
     * allocation (1300.00) exceeds its own line amount (1000.00) — never
     * producible through record()'s per-item cap (validateAllocations),
     * simulated directly.
     */
    public function test_net_allocated_to_item_exceeding_item_amount_makes_invoice_ambiguous(): void
    {
        $invoice = $this->invoice('1000.00');
        $tuition = $invoice->items->sole();

        $paymentA = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '1000.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        PaymentAllocation::create(['invoice_payment_id' => $paymentA->id, 'invoice_item_id' => $tuition->id, 'amount' => '1000.00']);
        $paymentB = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '300.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        PaymentAllocation::create(['invoice_payment_id' => $paymentB->id, 'invoice_item_id' => $tuition->id, 'amount' => '300.00']);

        $this->assertFalse($this->payments()->isAllocationClean($invoice->fresh()));
    }

    // ----- 21: idempotency is unaffected by the newly-clean repayment path --

    public function test_repayment_idempotency_after_attributed_refund_remains_unchanged(): void
    {
        [$invoice, $tuition] = $this->tuitionAndUniformFullyPaidThenTuitionPartiallyRefunded();
        $key = (string) Str::uuid();

        $first = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00',
            paymentMethod: 'cash', idempotencyKey: $key, actor: $this->accountant,
            allocations: [['invoice_item_id' => $tuition->id, 'amount' => '400.00']],
        );
        $second = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00',
            paymentMethod: 'cash', idempotencyKey: $key, actor: $this->accountant,
            allocations: [['invoice_item_id' => $tuition->id, 'amount' => '400.00']],
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, InvoicePayment::where('invoice_id', $invoice->id)->count(), 'original + one repayment, replay created nothing extra');
        $this->assertSame(3, PaymentAllocation::count(), 'original tuition+uniform (2) plus one repayment allocation (1) — the replay created nothing extra');
    }

    // ----- 26: Student Finance invoice-level totals stay correct -----------

    public function test_student_finance_view_reflects_correct_net_totals_through_the_new_clean_repayment_path(): void
    {
        [$invoice, $tuition] = $this->tuitionAndUniformFullyPaidThenTuitionPartiallyRefunded();

        $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student))
            ->assertOk()->assertSee('1100.00 EGP')->assertSee('400.00 EGP');

        $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['invoice_item_id' => $tuition->id, 'amount' => '400.00']],
        );

        $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student))
            ->assertOk()->assertSee('1500.00 EGP')->assertSee('0.00 EGP');
    }

    // ----- 27: cash balance is untouched by read-only cleanliness checks ---

    public function test_cash_balance_is_unchanged_by_cleanliness_and_remaining_capacity_reads(): void
    {
        [$invoice] = $this->tuitionAndUniformFullyPaidThenTuitionPartiallyRefunded();
        $balanceBefore = (string) $this->cash->fresh()->balance;

        $this->payments()->isAllocationClean($invoice);
        $this->payments()->isAllocationClean($invoice);
        $this->payments()->remainingAllocatableByItem($invoice);
        $this->payments()->remainingAllocatableByItem($invoice);

        $this->assertSame($balanceBefore, (string) $this->cash->fresh()->balance);
    }

    // ----- 28: an invalid repayment allocation rolls back everything -------

    public function test_invalid_repayment_allocation_rolls_back_payment_allocation_cash_transaction_and_balance(): void
    {
        [$invoice, $tuition, $uniform] = $this->tuitionAndUniformFullyPaidThenTuitionPartiallyRefunded();
        $balanceBefore = (string) $this->cash->fresh()->balance;
        $paymentCountBefore = InvoicePayment::where('invoice_id', $invoice->id)->count();
        $allocationCountBefore = PaymentAllocation::count();
        $cashTxCountBefore = CashTransaction::where('type', CashTransaction::TYPE_IN)->count();

        try {
            // Uniform has zero reopened capacity — allocating anything to it
            // must be rejected, and atomically.
            $this->payments()->record(
                invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00',
                paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
                allocations: [['invoice_item_id' => $uniform->id, 'amount' => '400.00']],
            );
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
        }

        $this->assertSame($paymentCountBefore, InvoicePayment::where('invoice_id', $invoice->id)->count(), 'no InvoicePayment was written');
        $this->assertSame($allocationCountBefore, PaymentAllocation::count(), 'no PaymentAllocation was written');
        $this->assertSame($cashTxCountBefore, CashTransaction::where('type', CashTransaction::TYPE_IN)->count(), 'no incoming CashTransaction was written');
        $this->assertSame($balanceBefore, (string) $this->cash->fresh()->balance, 'cash account balance is unchanged');
        $this->assertNotSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }
}
