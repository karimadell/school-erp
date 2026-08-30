<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\PaymentRefundAllocation;
use App\Models\Student;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finance V2, Phase 1D (docs/finance-v2-architecture.md §19 Phase 1D).
 *
 * Proves the new PaymentRefundAllocation invariant: for every NEW attributed
 * refund, SUM(PaymentRefundAllocation.amount) == PaymentRefund.amount, with
 * the same "never guess" discipline as Phase 1A-1C —
 *   - a payment with one allocation auto-attributes (unless non-refundable
 *     or over capacity);
 *   - a payment with multiple allocations requires an explicit split unless
 *     the refund exactly exhausts every remaining refundable balance;
 *   - a payment with zero allocations (or inconsistent allocation coverage)
 *     stays unattributed, exactly like Phase 1A/1C's compatibility fallback.
 *
 * No historical backfill: Phase 1D governs new refunds only.
 */
class FinanceV2Phase1DRefundAllocationTest extends FinanceOperationsTestCase
{
    private function refunds(): InvoiceRefundService
    {
        return app(InvoiceRefundService::class);
    }

    private function payments(): InvoicePaymentService
    {
        return app(InvoicePaymentService::class);
    }

    private function secondInvoiceItem(Invoice $invoice, string $amount, bool $nonRefundable = false): InvoiceItem
    {
        return InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'fee_id' => $this->fee->id,
            'description' => 'Вторая строка (Phase 1D test)',
            'unit_price' => $amount,
            'quantity' => 1,
            'amount' => $amount,
            'paid_amount' => '0.00',
            'remaining_amount' => $amount,
            'is_non_refundable' => $nonRefundable,
        ]);
    }

    /** A payment fully, explicitly allocated across the given [item => amount] pairs. */
    private function payAllocated(Invoice $invoice, array $itemAmounts, string $total): InvoicePayment
    {
        return $this->payments()->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            amount: $total,
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
            allocations: collect($itemAmounts)->map(fn ($amount, $itemId) => ['invoice_item_id' => $itemId, 'amount' => $amount])->values()->all(),
        );
    }

    // ----- Case B: exactly one PaymentAllocation ---------------------------

    public function test_single_allocation_partial_refund_auto_allocates(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1200.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $allocation = $payment->allocations->sole();

        $refund = $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '400.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->assertCount(1, $refund->allocations);
        $this->assertSame($allocation->id, $refund->allocations->first()->payment_allocation_id);
        $this->assertSame('400.00', (string) $refund->allocations->first()->amount);
    }

    public function test_single_allocation_full_refund_auto_allocates(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1200.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $refund = $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '1200.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->assertCount(1, $refund->allocations);
        $this->assertSame('1200.00', (string) $refund->allocations->first()->amount);
    }

    public function test_repeated_partial_refunds_respect_remaining_allocation_capacity(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1200.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $allocation = $payment->allocations->sole();

        $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '500.00', reason: 'a', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '300.00', reason: 'b', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);

        $this->assertSame('800.00', $allocation->refundedAmount());
        $this->assertSame(2, PaymentRefundAllocation::where('payment_allocation_id', $allocation->id)->count());
    }

    public function test_exceeding_allocation_remaining_capacity_is_rejected_atomically(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1200.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '1000.00', reason: 'a', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
        $balanceBefore = (string) $this->cash->fresh()->balance;

        try {
            // Only 200.00 remains refundable overall — this already fails
            // the existing gross per-payment cap before Phase 1D logic runs.
            $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '300.00', reason: 'b', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
        }

        $this->assertSame(1, PaymentRefund::count());
        $this->assertSame(1, PaymentRefundAllocation::count());
        $this->assertSame($balanceBefore, (string) $this->cash->fresh()->balance);
    }

    public function test_allocation_targeting_non_refundable_item_is_rejected(): void
    {
        $invoice = Invoice::create([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'customer_name' => $this->student->full_name, 'currency' => 'EGP',
            'subtotal_amount' => '500.00', 'total_amount' => '500.00', 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => '500.00', 'status' => 'unpaid',
            'due_date' => '2027-01-01', 'created_by' => $this->accountant->id,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, '2026');
        $invoice->save();
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'fee_id' => $this->fee->id, 'description' => 'Организационный взнос',
            'unit_price' => '500.00', 'quantity' => 1, 'amount' => '500.00',
            'paid_amount' => '0.00', 'remaining_amount' => '500.00', 'is_non_refundable' => true,
        ]);
        // A registration fee is protected at the aggregate invoice level
        // too (see PaymentRefundTest::test_non_refundable_registration_fee_is_protected).
        // That existing check would already reject this case; this test
        // exists purely to characterize the new, tighter per-allocation
        // guard for a payment with exactly one (non-refundable) allocation.
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '500.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->expectException(ValidationException::class);
        $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '100.00', reason: 'test', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
    }

    // ----- Case C/D: multiple PaymentAllocations ----------------------------

    public function test_multi_allocation_partial_refund_without_explicit_split_is_rejected(): void
    {
        $invoice = $this->invoice('1200.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '800.00');
        $invoice->update(['total_amount' => '2000.00', 'remaining_amount' => '2000.00']);
        $payment = $this->payAllocated($invoice, [$itemA->id => '1200.00', $itemB->id => '800.00'], '2000.00');
        $balanceBefore = (string) $this->cash->fresh()->balance;

        try {
            // 500.00 is a genuine partial amount — neither allocation's
            // remaining balance alone, nor the two combined, is uniquely
            // implied by this figure.
            $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '500.00', reason: 'test', idempotencyKey: (string) Str::uuid(), actor: $this->accountant);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
        }

        $this->assertSame(0, PaymentRefund::count());
        $this->assertSame(0, PaymentRefundAllocation::count());
        $this->assertSame($balanceBefore, (string) $this->cash->fresh()->balance);
    }

    public function test_multi_allocation_partial_refund_with_explicit_split_succeeds(): void
    {
        $invoice = $this->invoice('1200.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '800.00');
        $invoice->update(['total_amount' => '2000.00', 'remaining_amount' => '2000.00']);
        $payment = $this->payAllocated($invoice, [$itemA->id => '1200.00', $itemB->id => '800.00'], '2000.00');
        $allocationA = $payment->allocations->firstWhere('invoice_item_id', $itemA->id);
        $allocationB = $payment->allocations->firstWhere('invoice_item_id', $itemB->id);

        $refund = $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '500.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [
                ['payment_allocation_id' => $allocationA->id, 'amount' => '300.00'],
                ['payment_allocation_id' => $allocationB->id, 'amount' => '200.00'],
            ],
        );

        $this->assertCount(2, $refund->allocations);
        $this->assertSame('300.00', (string) $refund->allocations->firstWhere('payment_allocation_id', $allocationA->id)->amount);
        $this->assertSame('200.00', (string) $refund->allocations->firstWhere('payment_allocation_id', $allocationB->id)->amount);
        $this->assertSame(1, CashTransaction::where('category', CashTransaction::CATEGORY_REFUND)->count(), 'exactly one outgoing cash movement');
    }

    public function test_explicit_split_sum_mismatch_is_rejected_atomically(): void
    {
        $invoice = $this->invoice('1200.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '800.00');
        $invoice->update(['total_amount' => '2000.00', 'remaining_amount' => '2000.00']);
        $payment = $this->payAllocated($invoice, [$itemA->id => '1200.00', $itemB->id => '800.00'], '2000.00');
        $allocationA = $payment->allocations->firstWhere('invoice_item_id', $itemA->id);
        $allocationB = $payment->allocations->firstWhere('invoice_item_id', $itemB->id);

        try {
            $this->refunds()->refund(
                invoicePaymentId: $payment->id, amount: '500.00', reason: 'test',
                idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
                allocations: [
                    ['payment_allocation_id' => $allocationA->id, 'amount' => '200.00'],
                    ['payment_allocation_id' => $allocationB->id, 'amount' => '200.00'],
                ], // sums to 400.00, not 500.00
            );
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
        }

        $this->assertSame(0, PaymentRefund::count());
        $this->assertSame(0, PaymentRefundAllocation::count());
    }

    public function test_allocation_referencing_another_payment_is_rejected(): void
    {
        $invoiceOne = $this->invoice('1200.00');
        $paymentOne = $this->payments()->record(
            invoiceId: $invoiceOne->id, cashAccountId: $this->cash->id, amount: '1200.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $allocationOne = $paymentOne->allocations->sole();

        $studentTwo = Student::create([
            'last_name_ru' => 'ИвановВторой', 'first_name_ru' => 'Иван', 'phone' => '+2010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        ]);
        $invoiceTwo = Invoice::create([
            'student_id' => $studentTwo->id, 'academic_year_id' => $this->year->id,
            'customer_name' => $studentTwo->full_name, 'currency' => 'EGP',
            'subtotal_amount' => '1200.00', 'total_amount' => '1200.00', 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => '1200.00', 'status' => 'unpaid',
            'due_date' => '2027-01-01', 'created_by' => $this->accountant->id,
        ]);
        $invoiceTwo->invoice_number = Invoice::numberFor($invoiceTwo->id, '2026');
        $invoiceTwo->save();
        InvoiceItem::create([
            'invoice_id' => $invoiceTwo->id, 'fee_id' => $this->fee->id, 'description' => 'Обучение',
            'unit_price' => '1200.00', 'quantity' => 1, 'amount' => '1200.00',
            'paid_amount' => '0.00', 'remaining_amount' => '1200.00',
        ]);
        $paymentTwo = $this->payments()->record(
            invoiceId: $invoiceTwo->id, cashAccountId: $this->cash->id, amount: '1200.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->expectException(ValidationException::class);
        $this->refunds()->refund(
            invoicePaymentId: $paymentTwo->id, amount: '100.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['payment_allocation_id' => $allocationOne->id, 'amount' => '100.00']], // belongs to paymentOne
        );
        $this->assertSame(0, PaymentRefund::where('invoice_payment_id', $paymentTwo->id)->count());
    }

    public function test_exact_exhaustion_multi_allocation_refund_auto_allocates_each_remaining_balance(): void
    {
        $invoice = $this->invoice('1200.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '800.00');
        $invoice->update(['total_amount' => '2000.00', 'remaining_amount' => '2000.00']);
        $payment = $this->payAllocated($invoice, [$itemA->id => '1200.00', $itemB->id => '800.00'], '2000.00');
        $allocationA = $payment->allocations->firstWhere('invoice_item_id', $itemA->id);
        $allocationB = $payment->allocations->firstWhere('invoice_item_id', $itemB->id);

        // No allocations submitted — 2000.00 exactly exhausts both
        // remaining balances (1200.00 + 800.00), the one distribution
        // forced by the caps themselves.
        $refund = $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '2000.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->assertCount(2, $refund->allocations);
        $this->assertSame('1200.00', (string) $refund->allocations->firstWhere('payment_allocation_id', $allocationA->id)->amount);
        $this->assertSame('800.00', (string) $refund->allocations->firstWhere('payment_allocation_id', $allocationB->id)->amount);
    }

    public function test_multi_allocation_non_exhaustive_refund_is_never_auto_split(): void
    {
        $invoice = $this->invoice('1200.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '800.00');
        $invoice->update(['total_amount' => '2000.00', 'remaining_amount' => '2000.00']);
        $payment = $this->payAllocated($invoice, [$itemA->id => '1200.00', $itemB->id => '800.00'], '2000.00');

        // 1999.99 falls one cent short of exhausting both balances — must
        // never be silently distributed, no matter how close to full.
        $this->expectException(ValidationException::class);
        $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '1999.99', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
    }

    public function test_exact_exhaustion_excludes_non_refundable_lines_from_the_pool(): void
    {
        $invoice = $this->invoice('1200.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '800.00', nonRefundable: true);
        $invoice->update(['total_amount' => '2000.00', 'remaining_amount' => '2000.00']);
        $payment = $this->payAllocated($invoice, [$itemA->id => '1200.00', $itemB->id => '800.00'], '2000.00');
        $allocationA = $payment->allocations->firstWhere('invoice_item_id', $itemA->id);

        // Only item A (1200.00) is eligible; item B is non-refundable and
        // excluded from the pool entirely, so 1200.00 exactly exhausts the
        // *eligible* pool even though the payment itself totals 2000.00.
        $refund = $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '1200.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->assertCount(1, $refund->allocations);
        $this->assertSame($allocationA->id, $refund->allocations->first()->payment_allocation_id);
    }

    // ----- Case A: zero PaymentAllocations (historical compatibility) ------

    public function test_zero_allocation_payment_refund_succeeds_with_zero_refund_allocation_rows(): void
    {
        $invoice = $this->invoice('1200.00');
        $itemA = $invoice->items->first();
        $this->secondInvoiceItem($invoice, '800.00');
        $invoice->update(['total_amount' => '2000.00', 'remaining_amount' => '2000.00']);

        // Simulate a genuinely historical, pre-Phase-1D unattributed
        // payment (never producible by canonical code today) by writing it
        // directly — this makes the invoice allocation-ambiguous, which
        // Phase 1C then legitimately allows a second, real, unattributed
        // payment against.
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

        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '300.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $this->assertCount(0, $payment->allocations, 'this payment carries no allocation rows, by construction');

        $refund = $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '100.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->assertCount(0, $refund->allocations);
        $this->assertSame(0, PaymentRefundAllocation::count());
    }

    // ----- Case E: inconsistent PaymentAllocation coverage ------------------

    /**
     * @return array{0: Invoice, 1: InvoicePayment, 2: PaymentAllocation}
     *         An anomalous payment with PARTIAL PaymentAllocation coverage
     *         (500.00 of its own 1200.00 amount) — genuinely never
     *         producible by canonical code (Phase 1A/1B/1C always produce
     *         either full or zero coverage), simulated by direct
     *         persistence, exactly as it would already exist on disk if it
     *         somehow arose.
     */
    private function paymentWithPartialAllocationCoverage(): array
    {
        $invoice = $this->invoice('1200.00');
        $item = $invoice->items->first();
        $payment = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id, 'amount' => '1200.00',
            'payment_method' => 'cash', 'paid_at' => now(), 'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(), 'idempotency_hash' => hash('sha256', Str::random()),
        ]);
        $allocation = PaymentAllocation::create([
            'invoice_payment_id' => $payment->id, 'invoice_item_id' => $item->id, 'amount' => '500.00',
        ]);
        CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'created_by' => $this->accountant->id,
            'invoice_payment_id' => $payment->id, 'amount' => '1200.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Anomalous partial-coverage payment (test fixture)',
        ]);
        $invoice->forceFill(['paid_amount' => '1200.00', 'remaining_amount' => '0.00', 'status' => Invoice::STATUS_PAID])->save();

        return [$invoice, $payment, $allocation];
    }

    /**
     * Phase 1D correction — canonical policy: PARTIAL/inconsistent
     * PaymentAllocation coverage FAILS CLOSED unconditionally. Explicit
     * attribution is rejected here; the companion test below proves the
     * exact same fail-closed behavior for an omitted allocation request.
     */
    public function test_partial_allocation_coverage_with_explicit_allocations_is_rejected_atomically(): void
    {
        [, $payment, $allocation] = $this->paymentWithPartialAllocationCoverage();
        $balanceBefore = (string) $this->cash->fresh()->balance;

        try {
            $this->refunds()->refund(
                invoicePaymentId: $payment->id, amount: '100.00', reason: 'test',
                idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
                allocations: [['payment_allocation_id' => $allocation->id, 'amount' => '100.00']],
            );
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
        }

        $this->assertSame(0, PaymentRefund::count());
        $this->assertSame(0, PaymentRefundAllocation::count());
        $this->assertSame(0, CashTransaction::where('category', CashTransaction::CATEGORY_REFUND)->count());
        $this->assertSame($balanceBefore, (string) $this->cash->fresh()->balance);
    }

    /**
     * Phase 1D correction — the canonical policy change from the original
     * implementation: an OMITTED allocation request against
     * partial/inconsistent coverage must now ALSO fail closed, never fall
     * back to an unattributed refund. Doing so would expand a corrupted
     * historical state instead of containing it.
     */
    public function test_partial_allocation_coverage_with_omitted_allocations_is_rejected_atomically(): void
    {
        [, $payment] = $this->paymentWithPartialAllocationCoverage();
        $balanceBefore = (string) $this->cash->fresh()->balance;

        try {
            $this->refunds()->refund(
                invoicePaymentId: $payment->id, amount: '100.00', reason: 'test',
                idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
                // allocations omitted entirely — must still be rejected.
            );
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
        }

        $this->assertSame(0, PaymentRefund::count());
        $this->assertSame(0, PaymentRefundAllocation::count());
        $this->assertSame(0, CashTransaction::where('category', CashTransaction::CATEGORY_REFUND)->count());
        $this->assertSame($balanceBefore, (string) $this->cash->fresh()->balance);
    }

    // ----- Idempotency -------------------------------------------------------

    public function test_idempotent_retry_replays_the_original_refund_even_after_allocation_state_changes(): void
    {
        $invoice = $this->invoice('1200.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '800.00');
        $invoice->update(['total_amount' => '2000.00', 'remaining_amount' => '2000.00']);
        $payment = $this->payAllocated($invoice, [$itemA->id => '1200.00', $itemB->id => '800.00'], '2000.00');
        $allocationA = $payment->allocations->firstWhere('invoice_item_id', $itemA->id);
        $allocationB = $payment->allocations->firstWhere('invoice_item_id', $itemB->id);
        $key = (string) Str::uuid();

        $original = $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '300.00', reason: 'test',
            idempotencyKey: $key, actor: $this->accountant,
            allocations: [['payment_allocation_id' => $allocationA->id, 'amount' => '300.00']],
        );

        // Change allocation/refund state after the original request — a
        // second, unrelated refund against the other allocation.
        $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '200.00', reason: 'unrelated',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['payment_allocation_id' => $allocationB->id, 'amount' => '200.00']],
        );

        // Retry the ORIGINAL request with the SAME key — must replay the
        // original refund exactly, never re-validate against the new state.
        $retry = $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '300.00', reason: 'test',
            idempotencyKey: $key, actor: $this->accountant,
            allocations: [['payment_allocation_id' => $allocationA->id, 'amount' => '300.00']],
        );

        $this->assertSame($original->id, $retry->id);
        $this->assertSame(2, PaymentRefund::count(), 'exactly the original plus the one unrelated refund — no duplicate from the retry');
        $this->assertSame(2, PaymentRefundAllocation::count(), 'the retry created no duplicate refund allocation');
        $this->assertSame(2, CashTransaction::where('category', CashTransaction::CATEGORY_REFUND)->count(), 'the retry created no duplicate cash transaction');
    }

    public function test_identical_explicit_split_retry_replays_the_original_refund(): void
    {
        $invoice = $this->invoice('1200.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '800.00');
        $invoice->update(['total_amount' => '2000.00', 'remaining_amount' => '2000.00']);
        $payment = $this->payAllocated($invoice, [$itemA->id => '1200.00', $itemB->id => '800.00'], '2000.00');
        $allocationA = $payment->allocations->firstWhere('invoice_item_id', $itemA->id);
        $allocationB = $payment->allocations->firstWhere('invoice_item_id', $itemB->id);
        $key = (string) Str::uuid();
        $split = [
            ['payment_allocation_id' => $allocationA->id, 'amount' => '300.00'],
            ['payment_allocation_id' => $allocationB->id, 'amount' => '200.00'],
        ];

        $original = $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '500.00', reason: 'test', idempotencyKey: $key, actor: $this->accountant, allocations: $split);
        $retry = $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '500.00', reason: 'test', idempotencyKey: $key, actor: $this->accountant, allocations: $split);

        $this->assertSame($original->id, $retry->id);
        $this->assertSame(1, PaymentRefund::count());
        $this->assertSame(2, PaymentRefundAllocation::count());
        $this->assertSame(1, CashTransaction::where('category', CashTransaction::CATEGORY_REFUND)->count());
    }

    public function test_different_explicit_split_with_same_key_is_rejected_as_idempotency_conflict(): void
    {
        $invoice = $this->invoice('1200.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '800.00');
        $invoice->update(['total_amount' => '2000.00', 'remaining_amount' => '2000.00']);
        $payment = $this->payAllocated($invoice, [$itemA->id => '1200.00', $itemB->id => '800.00'], '2000.00');
        $allocationA = $payment->allocations->firstWhere('invoice_item_id', $itemA->id);
        $allocationB = $payment->allocations->firstWhere('invoice_item_id', $itemB->id);
        $key = (string) Str::uuid();

        $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '500.00', reason: 'test', idempotencyKey: $key, actor: $this->accountant,
            allocations: [
                ['payment_allocation_id' => $allocationA->id, 'amount' => '300.00'],
                ['payment_allocation_id' => $allocationB->id, 'amount' => '200.00'],
            ],
        );

        try {
            // Same key, same total amount, but a materially different split.
            $this->refunds()->refund(
                invoicePaymentId: $payment->id, amount: '500.00', reason: 'test', idempotencyKey: $key, actor: $this->accountant,
                allocations: [
                    ['payment_allocation_id' => $allocationA->id, 'amount' => '100.00'],
                    ['payment_allocation_id' => $allocationB->id, 'amount' => '400.00'],
                ],
            );
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('idempotency_key', $e->errors());
        }

        // The original refund is untouched — still exactly its own split.
        $this->assertSame(1, PaymentRefund::count());
        $this->assertSame('300.00', (string) PaymentRefundAllocation::where('payment_allocation_id', $allocationA->id)->sole()->amount);
        $this->assertSame('200.00', (string) PaymentRefundAllocation::where('payment_allocation_id', $allocationB->id)->sole()->amount);
        $this->assertSame(1, CashTransaction::where('category', CashTransaction::CATEGORY_REFUND)->count());
    }

    public function test_reordered_equivalent_allocations_do_not_cause_a_false_idempotency_conflict(): void
    {
        $invoice = $this->invoice('1200.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '800.00');
        $invoice->update(['total_amount' => '2000.00', 'remaining_amount' => '2000.00']);
        $payment = $this->payAllocated($invoice, [$itemA->id => '1200.00', $itemB->id => '800.00'], '2000.00');
        $allocationA = $payment->allocations->firstWhere('invoice_item_id', $itemA->id);
        $allocationB = $payment->allocations->firstWhere('invoice_item_id', $itemB->id);
        $key = (string) Str::uuid();

        $original = $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '500.00', reason: 'test', idempotencyKey: $key, actor: $this->accountant,
            allocations: [
                ['payment_allocation_id' => $allocationA->id, 'amount' => '300.00'],
                ['payment_allocation_id' => $allocationB->id, 'amount' => '200.00'],
            ],
        );

        // Same semantic split, lines reordered and amount formatted
        // differently ('200' vs '200.00') — must still replay.
        $retry = $this->refunds()->refund(
            invoicePaymentId: $payment->id, amount: '500.00', reason: 'test', idempotencyKey: $key, actor: $this->accountant,
            allocations: [
                ['payment_allocation_id' => $allocationB->id, 'amount' => '200'],
                ['payment_allocation_id' => $allocationA->id, 'amount' => '300.00'],
            ],
        );

        $this->assertSame($original->id, $retry->id);
        $this->assertSame(1, PaymentRefund::count());
        $this->assertSame(2, PaymentRefundAllocation::count());
    }

    public function test_auto_allocation_retry_remains_idempotent(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1200.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $key = (string) Str::uuid();

        // Case B auto-attribution — allocations omitted on both calls.
        $original = $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '400.00', reason: 'test', idempotencyKey: $key, actor: $this->accountant);
        $retry = $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '400.00', reason: 'test', idempotencyKey: $key, actor: $this->accountant);

        $this->assertSame($original->id, $retry->id);
        $this->assertSame(1, PaymentRefund::count());
        $this->assertSame(1, PaymentRefundAllocation::count());
        $this->assertSame(1, CashTransaction::where('category', CashTransaction::CATEGORY_REFUND)->count());
    }

    public function test_exact_exhaustion_auto_allocation_retry_remains_idempotent(): void
    {
        $invoice = $this->invoice('1200.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '800.00');
        $invoice->update(['total_amount' => '2000.00', 'remaining_amount' => '2000.00']);
        $payment = $this->payAllocated($invoice, [$itemA->id => '1200.00', $itemB->id => '800.00'], '2000.00');
        $key = (string) Str::uuid();

        // Case D exact exhaustion — allocations omitted on both calls.
        $original = $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '2000.00', reason: 'test', idempotencyKey: $key, actor: $this->accountant);
        $retry = $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '2000.00', reason: 'test', idempotencyKey: $key, actor: $this->accountant);

        $this->assertSame($original->id, $retry->id);
        $this->assertSame(1, PaymentRefund::count());
        $this->assertSame(2, PaymentRefundAllocation::count());
        $this->assertSame(1, CashTransaction::where('category', CashTransaction::CATEGORY_REFUND)->count());
    }

    /**
     * Historical/pre-Phase-1D style refund replay: a caller that never
     * knows about (or supplies) allocations at all, against a genuinely
     * zero-allocation payment — the base hash alone, unchanged from before
     * Phase 1D, is what decides this replay.
     */
    public function test_historical_style_refund_replay_remains_compatible(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = $this->payments()->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1200.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        // Force a genuinely zero-allocation payment shape by bypassing
        // service auto-allocation — mirrors a pre-Phase-1A/1D historical row.
        PaymentAllocation::where('invoice_payment_id', $payment->id)->delete();
        $key = (string) Str::uuid();

        $original = $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '400.00', reason: 'test', idempotencyKey: $key, actor: $this->accountant);
        $retry = $this->refunds()->refund(invoicePaymentId: $payment->id, amount: '400.00', reason: 'test', idempotencyKey: $key, actor: $this->accountant);

        $this->assertSame($original->id, $retry->id);
        $this->assertSame(1, PaymentRefund::count());
        $this->assertSame(0, PaymentRefundAllocation::count());
        $this->assertSame(1, CashTransaction::where('category', CashTransaction::CATEGORY_REFUND)->count());
    }
}
