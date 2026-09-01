<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\PaymentAllocation;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use App\Services\Finance\StudentCreditService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finance V2, Phase 1A — additive Payment Allocation foundation
 * (docs/finance-v2-architecture.md §7).
 *
 * Proves the new payment_allocations table/model/service extension is
 * correct in isolation: schema, decimal semantics, single-item
 * auto-allocation, explicit allocation validation, atomicity, and
 * non-interference with refunds, overpayment protection, and StudentCredit.
 *
 * Phase 1A's "unallocated multi-item payment is not an error" compatibility
 * state was unconditional; Phase 1C narrowed it to genuinely
 * allocation-ambiguous invoices only (see FinanceV2Phase1CAllocationInvariantTest
 * and FinanceV2Phase1BAllocationTest for that exception).
 */
class PaymentAllocationTest extends FinanceOperationsTestCase
{
    private function secondInvoiceItem(Invoice $invoice, string $amount): InvoiceItem
    {
        return InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'fee_id' => $this->fee->id,
            'description' => 'Вторая строка (Phase 1A test)',
            'unit_price' => $amount,
            'quantity' => 1,
            'amount' => $amount,
            'paid_amount' => '0.00',
            'remaining_amount' => $amount,
        ]);
    }

    public function test_payment_allocations_table_schema_matches_the_design(): void
    {
        $this->assertTrue(Schema::hasTable('payment_allocations'));
        $this->assertTrue(Schema::hasColumns('payment_allocations', [
            'id', 'invoice_payment_id', 'invoice_item_id', 'amount', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('payment_allocations', 'updated_at'));
    }

    public function test_invoice_payment_allocations_relation_returns_its_rows(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->assertCount(1, $payment->allocations);
        $this->assertInstanceOf(PaymentAllocation::class, $payment->allocations->first());
        $this->assertSame($payment->id, $payment->allocations->first()->invoice_payment_id);
    }

    public function test_amount_uses_decimal_two_place_money_semantics(): void
    {
        $invoice = $this->invoice('1000.00');
        $itemB = $this->secondInvoiceItem($invoice, '0.00'); // amount adjusted below via total; see split
        // Re-split the 1000.00 total across three lines summing exactly,
        // proving decimal-string (not float) arithmetic: 333.33 + 333.33 + 333.34 = 1000.00.
        $itemA = $invoice->items()->where('id', '!=', $itemB->id)->first();
        $itemA->update(['amount' => '333.33', 'remaining_amount' => '333.33']);
        $itemB->update(['amount' => '333.33', 'remaining_amount' => '333.33']);
        $itemC = $this->secondInvoiceItem($invoice, '333.34');

        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [
                ['invoice_item_id' => $itemA->id, 'amount' => '333.33'],
                ['invoice_item_id' => $itemB->id, 'amount' => '333.33'],
                ['invoice_item_id' => $itemC->id, 'amount' => '333.34'],
            ],
        );

        $this->assertCount(3, $payment->allocations);
        $this->assertSame('333.33', (string) $payment->allocations->where('invoice_item_id', $itemA->id)->first()->amount);
        $this->assertSame('333.34', (string) $payment->allocations->where('invoice_item_id', $itemC->id)->first()->amount);
    }

    public function test_single_item_invoice_full_payment_auto_allocates_exactly_once(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->assertCount(1, $payment->allocations);
        $allocation = $payment->allocations->first();
        $this->assertSame($invoice->items->first()->id, $allocation->invoice_item_id);
        $this->assertSame('1000.00', (string) $allocation->amount);
    }

    public function test_single_item_invoice_partial_payment_auto_allocates_exactly_the_payment_amount(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '400.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->assertCount(1, $payment->allocations);
        $this->assertSame('400.00', (string) $payment->allocations->first()->amount);
        $this->assertSame($invoice->items->first()->id, $payment->allocations->first()->invoice_item_id);
    }

    /**
     * Finance V2, Phase 1C (docs/finance-v2-architecture.md §19 Phase 1C)
     * closed this: a brand-new multi-item invoice has zero prior payments
     * and zero refunds, so it is always allocation-clean, and a clean
     * multi-item invoice has no excuse for an unallocated payment anymore.
     * This test used to characterize Phase 1A's original "must not error"
     * compatibility state for exactly this shape; Phase 1C's central
     * safety test (FinanceV2Phase1CAllocationInvariantTest) now proves the
     * opposite is true here, and the still-valid "genuinely ambiguous
     * invoice" exception is covered there and in
     * FinanceV2Phase1BAllocationTest instead.
     */
    public function test_multi_item_payment_without_allocation_is_rejected_on_a_clean_invoice(): void
    {
        $invoice = $this->invoice('1000.00');
        $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);

        $this->expectException(ValidationException::class);
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '900.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
    }

    public function test_explicit_valid_allocations_create_the_exact_submitted_rows(): void
    {
        $invoice = $this->invoice('1000.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);

        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '800.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [
                ['invoice_item_id' => $itemA->id, 'amount' => '600.00'],
                ['invoice_item_id' => $itemB->id, 'amount' => '200.00'],
            ],
        );

        $this->assertCount(2, $payment->allocations);
        $this->assertSame('600.00', (string) $payment->allocations->where('invoice_item_id', $itemA->id)->first()->amount);
        $this->assertSame('200.00', (string) $payment->allocations->where('invoice_item_id', $itemB->id)->first()->amount);
    }

    public function test_supplied_allocation_sum_mismatch_is_rejected_and_nothing_is_written(): void
    {
        $invoice = $this->invoice('1000.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);

        $this->expectException(ValidationException::class);
        try {
            app(InvoicePaymentService::class)->record(
                invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '800.00',
                paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
                allocations: [
                    ['invoice_item_id' => $itemA->id, 'amount' => '600.00'],
                    ['invoice_item_id' => $itemB->id, 'amount' => '100.00'], // sums to 700, not 800
                ],
            );
        } finally {
            $this->assertSame(0, InvoicePayment::count());
            $this->assertSame(0, PaymentAllocation::count());
            $this->assertSame(0, CashTransaction::count());
        }
    }

    public function test_allocation_referencing_an_invoice_item_from_another_invoice_is_rejected(): void
    {
        $invoiceA = $this->invoice('1000.00');
        $invoiceB = $this->invoice('500.00');
        $foreignItem = $invoiceB->items->first();

        $this->expectException(ValidationException::class);
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoiceA->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['invoice_item_id' => $foreignItem->id, 'amount' => '1000.00']],
        );
    }

    public function test_allocation_amount_cannot_be_zero(): void
    {
        $invoice = $this->invoice('1000.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '0.00');

        $this->expectException(ValidationException::class);
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [
                ['invoice_item_id' => $itemA->id, 'amount' => '1000.00'],
                ['invoice_item_id' => $itemB->id, 'amount' => '0.00'],
            ],
        );
    }

    public function test_allocation_amount_cannot_be_negative(): void
    {
        $invoice = $this->invoice('1000.00');
        $itemA = $invoice->items->first();
        $itemB = $this->secondInvoiceItem($invoice, '500.00');
        $invoice->update(['total_amount' => '1500.00', 'remaining_amount' => '1500.00']);

        $this->expectException(ValidationException::class);
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [
                ['invoice_item_id' => $itemA->id, 'amount' => '1500.00'],
                ['invoice_item_id' => $itemB->id, 'amount' => '-500.00'],
            ],
        );
    }

    public function test_payment_allocations_and_cash_transaction_are_written_atomically(): void
    {
        $invoice = $this->invoice('1000.00');
        $itemA = $invoice->items->first();

        // Positive proof: on success, payment + allocation + cash transaction
        // all exist together, in the same transaction.
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['invoice_item_id' => $itemA->id, 'amount' => '1000.00']],
        );
        $this->assertSame(1, InvoicePayment::count());
        $this->assertSame(1, PaymentAllocation::count());
        $this->assertSame(1, CashTransaction::count());
        $this->assertNotNull($payment->cashTransaction);

        // Negative proof (rollback): an invalid allocation on a second
        // payment must leave zero new rows of any of the three kinds.
        $invoiceB = $this->invoice('500.00');
        try {
            app(InvoicePaymentService::class)->record(
                invoiceId: $invoiceB->id, cashAccountId: $this->cash->id, amount: '500.00',
                paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
                allocations: [['invoice_item_id' => $itemA->id, 'amount' => '500.00']], // wrong invoice's item
            );
        } catch (ValidationException) {
            // expected
        }
        $this->assertSame(1, InvoicePayment::count(), 'no new InvoicePayment from the failed attempt');
        $this->assertSame(1, PaymentAllocation::count(), 'no new PaymentAllocation from the failed attempt');
        $this->assertSame(1, CashTransaction::count(), 'no new CashTransaction from the failed attempt');
    }

    public function test_overpayment_beyond_remaining_amount_still_rejected_with_allocation_present(): void
    {
        $invoice = $this->invoice('1000.00');
        $itemA = $invoice->items->first();

        $this->expectException(ValidationException::class);
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1500.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['invoice_item_id' => $itemA->id, 'amount' => '1500.00']],
        );
    }

    public function test_existing_refund_behavior_still_succeeds_after_payment_allocation_exists(): void
    {
        $invoice = $this->invoice('1000.00');
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $this->cash->id, amount: '1000.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $this->assertCount(1, $payment->allocations);

        $refund = app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id, amount: '400.00', reason: 'Phase 1A compatibility test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->assertSame('400.00', (string) $refund->amount);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->fresh()->status);
        // Unaffected: PaymentAllocation from Phase 1A carries no refund
        // awareness yet (that is Phase 1D) — the allocation row is untouched.
        $this->assertCount(1, $payment->fresh()->allocations);
    }

    public function test_student_credit_application_does_not_create_payment_allocation(): void
    {
        $invoice = $this->invoice('1000.00');
        $item = $invoice->items->first();

        // Minimal valid fixture chain for StudentCredit's required
        // source_adjustment_id FK (StudentCredit::create()'s only real call
        // site is TariffAdjustmentService — this reproduces just enough of
        // its schema prerequisites to reach StudentCreditService::apply()).
        $feePrice = \App\Models\FeePrice::create([
            'fee_id' => $this->fee->id, 'academic_year_id' => $this->year->id,
            'amount' => '1000.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date,
            'is_active' => true,
        ]);
        $coverageId = \Illuminate\Support\Facades\DB::table('service_coverages')->insertGetId([
            'student_id' => $this->student->id, 'fee_id' => $this->fee->id, 'invoice_item_id' => $item->id,
            'fee_price_id' => $feePrice->id, 'coverage_start' => now()->toDateString(), 'coverage_end' => now()->addYear()->toDateString(),
            'billing_unit' => 'monthly', 'original_unit_price' => '1000.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $adjustmentId = \Illuminate\Support\Facades\DB::table('tariff_adjustments')->insertGetId([
            'student_id' => $this->student->id, 'fee_id' => $this->fee->id, 'service_coverage_id' => $coverageId,
            'new_fee_price_id' => $feePrice->id, 'status' => 'posted', 'kind' => 'credit',
            'total_difference' => '300.00', 'currency' => 'EGP',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $credit = \App\Models\StudentCredit::create([
            'student_id' => $this->student->id,
            'source_adjustment_id' => $adjustmentId,
            'original_amount' => '300.00',
            'consumed_amount' => '0.00',
            'available_amount' => '300.00',
            'status' => \App\Models\StudentCredit::STATUS_AVAILABLE,
        ]);

        app(StudentCreditService::class)->apply($credit, $invoice, '300.00', (string) Str::uuid(), $this->accountant);

        $this->assertSame(0, PaymentAllocation::count());
        $this->assertSame(0, InvoicePayment::count());
        // Corrective pass #2 (P0 Blocker 2): since credit application
        // creates zero PaymentAllocation rows, it structurally cannot
        // create any PaymentAllocationCoveragePeriod rows either — that
        // layer only ever fires inside InvoicePaymentService::record()'s
        // own allocation-writing step, which apply() never calls.
        $this->assertSame(0, \App\Models\PaymentAllocationCoveragePeriod::count());
    }
}
