<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentAllocation;
use App\Services\Finance\CashSessionService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finance V2, Phase 1B (docs/finance-v2-architecture.md §19 Phase 1B).
 *
 * Covers the two behaviors Phase 1A deliberately left open:
 *   1. The per-InvoiceItem outstanding cap — an item can never receive
 *      more allocation, across all payments, than its own line amount.
 *   2. Historical-unallocated-invoice compatibility — an invoice with a
 *      payment recorded before allocation tracking existed (or via a
 *      caller Phase 1B never updated) cannot safely support a new
 *      explicit per-item split, because we cannot know which item the old
 *      money paid down. Such an invoice must be characterized, not
 *      guessed at: explicit allocation is rejected at the service layer,
 *      and the existing-invoice payment screen falls back to Phase 1A's
 *      unallocated behavior rather than showing a misleading UI.
 *
 * Entry-point-specific "no allocations submitted" / "explicit allocations
 * succeed" behavior for Classic Invoice and the existing-invoice payment
 * screen lives in PaymentAllocationEntryPointCharacterizationTest.
 */
class FinanceV2Phase1BAllocationTest extends MassBillingTestCase
{
    private function registrationFee(string $amount = '500.00'): Fee
    {
        $fee = Fee::create(['name_ru' => 'Организационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'payment_period' => 'yearly', 'is_active' => true]);

        return $fee;
    }

    private function cashAccount(): CashAccount
    {
        $account = CashAccount::operating();
        if (! app(CashSessionService::class)->activeFor($account)) {
            app(CashSessionService::class)->open($account, $this->accountant);
        }

        return $account;
    }

    /** @return array{0: Invoice, 1: \App\Models\InvoiceItem, 2: \App\Models\InvoiceItem} */
    private function issueCleanMultiItemInvoice(string $suffix): array
    {
        $student = $this->enrolledStudent(suffix: $suffix);
        $feeA = $this->registrationFee('500.00');
        $feeB = $this->tuition; // 1200.00

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'fees' => [$feeA->id, $feeB->id],
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::where('student_id', $student->id)->sole();
        $itemA = $invoice->items()->where('fee_id', $feeA->id)->sole();
        $itemB = $invoice->items()->where('fee_id', $feeB->id)->sole();

        return [$invoice, $itemA, $itemB];
    }

    public function test_per_item_outstanding_cap_is_enforced_across_separate_payments(): void
    {
        [$invoice, $itemA, $itemB] = $this->issueCleanMultiItemInvoice('CapEnforced');
        $account = $this->cashAccount();

        // First payment fully allocates item A (500.00 line amount).
        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '500.00', 'payment_method' => 'cash', 'cash_account_id' => $account->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$itemA->id => '500.00'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('500.00', (string) PaymentAllocation::where('invoice_item_id', $itemA->id)->sole()->amount);

        // A second payment tries to allocate more to item A than it has
        // remaining (0.00) — must be rejected, and nothing from this
        // second attempt written.
        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '100.00', 'payment_method' => 'cash', 'cash_account_id' => $account->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$itemA->id => '50.00', $itemB->id => '50.00'],
        ]);
        $response->assertSessionHasErrors('allocations');

        $this->assertSame(1, InvoicePayment::count(), 'only the first payment was recorded');
        $this->assertSame(1, PaymentAllocation::count(), 'the rejected second payment wrote nothing');
    }

    public function test_per_item_outstanding_cap_allows_exactly_the_remaining_amount(): void
    {
        [$invoice, $itemA, $itemB] = $this->issueCleanMultiItemInvoice('CapExact');
        $account = $this->cashAccount();

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '500.00', 'payment_method' => 'cash', 'cash_account_id' => $account->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$itemA->id => '200.00', $itemB->id => '300.00'],
        ])->assertSessionHasNoErrors();

        // Item A has 300.00 remaining (500.00 - 200.00). Allocating exactly
        // that on the next payment must succeed — the cap is inclusive.
        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '300.00', 'payment_method' => 'cash', 'cash_account_id' => $account->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$itemA->id => '300.00'],
        ]);
        $response->assertSessionHasNoErrors();

        $total = PaymentAllocation::where('invoice_item_id', $itemA->id)->pluck('amount')
            ->reduce(fn (string $carry, $value) => bcadd($carry, (string) $value, 2), '0.00');
        $this->assertSame('500.00', $total);
    }

    public function test_historical_unallocated_payment_makes_the_invoice_unsafe_for_explicit_allocation(): void
    {
        [$invoice, $itemA, $itemB] = $this->issueCleanMultiItemInvoice('Historical');
        $account = $this->cashAccount();

        // Simulate a payment recorded before Phase 1 allocation tracking
        // existed — the same call shape Phase 1A's fallback (and the
        // orphaned InvoiceController::pay() caller) still produces:
        // allocations omitted entirely against a multi-item invoice.
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $account->id,
            amount: '300.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );
        $this->assertSame(0, PaymentAllocation::count(), 'the historical payment carries no allocation rows, by construction');

        // The invoice is no longer allocation-clean. A caller that now
        // tries to submit an explicit split must be rejected — never
        // guessed at which item the earlier 300.00 actually paid down.
        $this->expectException(ValidationException::class);
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $account->id,
            amount: '100.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
            allocations: [['invoice_item_id' => $itemA->id, 'amount' => '100.00']],
        );
    }

    public function test_existing_invoice_payment_screen_falls_back_to_unallocated_for_a_historically_unallocated_invoice(): void
    {
        [$invoice, , ] = $this->issueCleanMultiItemInvoice('HistoricalUi');
        $account = $this->cashAccount();

        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $account->id,
            amount: '300.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        // The payment form must not offer (and must not require) per-item
        // allocation for an invoice we cannot safely compute remaining
        // capacity for.
        $this->actingAs($this->accountant)
            ->get(route('dashboard.invoices.payments.create', $invoice))
            ->assertOk()
            ->assertViewHas('allocationClean', false);

        // Submitting a normal payment with no allocations must still
        // succeed unallocated — exactly Phase 1A's existing behavior,
        // unchanged for this invoice.
        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '200.00', 'payment_method' => 'cash', 'cash_account_id' => $account->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $response->assertSessionHasNoErrors();

        $this->assertSame(2, InvoicePayment::count());
        $this->assertSame(0, PaymentAllocation::count());
    }

    public function test_existing_invoice_payment_screen_offers_allocation_for_a_clean_multi_item_invoice(): void
    {
        [$invoice, , ] = $this->issueCleanMultiItemInvoice('CleanUi');

        $this->actingAs($this->accountant)
            ->get(route('dashboard.invoices.payments.create', $invoice))
            ->assertOk()
            ->assertViewHas('allocationClean', true);
    }

    public function test_allocation_referencing_an_item_from_another_invoice_is_rejected(): void
    {
        [$invoiceOne, $itemA, ] = $this->issueCleanMultiItemInvoice('CrossOne');
        [$invoiceTwo, , ] = $this->issueCleanMultiItemInvoice('CrossTwo');
        $account = $this->cashAccount();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoiceTwo), [
            'amount' => '100.00', 'payment_method' => 'cash', 'cash_account_id' => $account->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$itemA->id => '100.00'], // belongs to $invoiceOne
        ]);
        $response->assertSessionHasErrors('allocations');
        $this->assertSame(0, InvoicePayment::where('invoice_id', $invoiceTwo->id)->count());
    }

    public function test_allocation_sum_mismatch_is_rejected(): void
    {
        [$invoice, $itemA, $itemB] = $this->issueCleanMultiItemInvoice('SumMismatch');
        $account = $this->cashAccount();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store', $invoice), [
            'amount' => '500.00', 'payment_method' => 'cash', 'cash_account_id' => $account->id,
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [$itemA->id => '200.00', $itemB->id => '200.00'], // sums to 400.00, not 500.00
        ]);
        $response->assertSessionHasErrors('allocations');
        $this->assertSame(0, InvoicePayment::count());
    }

    /**
     * Refund/re-payment compatibility gate (post-Phase-1B safety
     * correction). InvoiceRefundService::refund() never mutates or deletes
     * PaymentAllocation rows — there is no payment_refund_allocations table
     * yet (Phase 1D) to say which item a refund gave money back from. So
     * once any refund exists against an invoice, its PaymentAllocation sums
     * are gross and permanently untrustworthy for a per-item cap: an item
     * that was fully allocated and then partially refunded still reads as
     * fully allocated. isAllocationClean() and validateAllocations() must
     * both treat any refund as allocation-ambiguous and fall back to Phase
     * 1A's unallocated path — never guess which item the refund came from.
     */
    public function test_isAllocationClean_becomes_false_once_any_refund_exists(): void
    {
        [$invoice, $itemA, ] = $this->issueCleanMultiItemInvoice('RefundGateClean');
        $account = $this->cashAccount();

        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $account->id, amount: '500.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['invoice_item_id' => $itemA->id, 'amount' => '500.00']],
        );
        $this->assertTrue(app(InvoicePaymentService::class)->isAllocationClean($invoice->fresh()));

        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id, amount: '100.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->assertFalse(app(InvoicePaymentService::class)->isAllocationClean($invoice->fresh()));
    }

    public function test_repayment_after_a_refund_falls_back_to_unallocated_instead_of_mis_capping(): void
    {
        [$invoice, $itemA, ] = $this->issueCleanMultiItemInvoice('RefundGateRepay');
        $account = $this->cashAccount();

        // Fully pay item A (500.00), then refund 100.00 of that payment —
        // item A genuinely has 100.00 outstanding again, but its gross
        // PaymentAllocation sum still reads as 500.00 / 500.00.
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $account->id, amount: '500.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['invoice_item_id' => $itemA->id, 'amount' => '500.00']],
        );
        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id, amount: '100.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        // An explicit allocation attempt must be rejected — never silently
        // mis-capped, never guessed.
        $this->expectException(ValidationException::class);
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $account->id, amount: '100.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['invoice_item_id' => $itemA->id, 'amount' => '100.00']],
        );
    }

    public function test_repayment_after_a_refund_succeeds_unallocated_when_no_allocations_are_supplied(): void
    {
        [$invoice, $itemA, ] = $this->issueCleanMultiItemInvoice('RefundGateFallback');
        $account = $this->cashAccount();

        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $account->id, amount: '500.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['invoice_item_id' => $itemA->id, 'amount' => '500.00']],
        );
        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id, amount: '100.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        // The existing-invoice payment screen must stop offering per-item
        // allocation for this invoice once it has a refund.
        $this->actingAs($this->accountant)
            ->get(route('dashboard.invoices.payments.create', $invoice))
            ->assertOk()
            ->assertViewHas('allocationClean', false);

        // A caller that (correctly) supplies no allocations must still
        // succeed — Phase 1A's unallocated fallback, unchanged.
        $repayment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $account->id, amount: '100.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );
        $this->assertSame('100.00', (string) $repayment->amount);
        $this->assertCount(0, $repayment->allocations);
    }
}
