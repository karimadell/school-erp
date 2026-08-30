<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
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
 * Finance V2, Phase 1C (docs/finance-v2-architecture.md §19 Phase 1C).
 *
 * Phase 1A introduced PaymentAllocation as additive and optional. Phase 1B
 * gave Classic Invoice and the existing-invoice payment screen explicit
 * per-item allocation UI, gated on InvoicePaymentService::isAllocationClean().
 * Both phases left one hole open at the *service* layer:
 * InvoicePaymentService::record() itself would still happily create an
 * unallocated payment against an allocation-clean multi-item invoice if a
 * caller simply omitted $allocations — every caller reachable from the UI
 * had already been updated not to do this, but nothing at the canonical
 * write boundary actually enforced it.
 *
 * Phase 1C closes that hole: record() now decides for itself, inside its own
 * transaction and invoice lock, whether a multi-item invoice is
 * allocation-clean or allocation-ambiguous, and:
 *   - rejects an omitted (or empty) allocation against a clean invoice;
 *   - continues to allow (and never auto-distributes) an omitted allocation
 *     against a genuinely ambiguous invoice — the "grandfather" exception,
 *     for both of the two ways an invoice becomes ambiguous (a historical
 *     unallocated payment, or any refund).
 */
class FinanceV2Phase1CAllocationInvariantTest extends MassBillingTestCase
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

    /**
     * Genuinely pre-Phase-1C production data can have an InvoicePayment
     * with zero PaymentAllocation rows against it — that is exactly what
     * this simulates, by writing the rows directly rather than inventing
     * which item the money paid down. Never done through
     * InvoicePaymentService::record(), which is precisely the path Phase
     * 1C now closes for a currently-clean invoice.
     */
    private function insertLegacyUnallocatedPayment(Invoice $invoice, CashAccount $account, string $amount): InvoicePayment
    {
        $payment = InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'cash_account_id' => $account->id,
            'amount' => $amount,
            'payment_method' => 'cash',
            'paid_at' => now(),
            'created_by' => $this->accountant->id,
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_hash' => hash('sha256', 'legacy-'.Str::random()),
        ]);
        CashTransaction::create([
            'cash_account_id' => $account->id,
            'created_by' => $this->accountant->id,
            'invoice_payment_id' => $payment->id,
            'amount' => $amount,
            'type' => CashTransaction::TYPE_IN,
            'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Legacy pre-Phase-1 payment (test fixture)',
        ]);
        $invoice->refreshPaymentStatus();

        return $payment;
    }

    /**
     * Central Phase 1C safety test. A clean multi-item invoice has no
     * excuse for an unallocated payment — record() must reject it outright,
     * and atomically: nothing written anywhere, cash balance untouched.
     */
    public function test_clean_multi_item_invoice_with_null_allocations_is_rejected_atomically(): void
    {
        [$invoice, , ] = $this->issueCleanMultiItemInvoice('NullReject');
        $account = $this->cashAccount();
        $balanceBefore = (string) $account->fresh()->balance;

        try {
            app(InvoicePaymentService::class)->record(
                invoiceId: $invoice->id,
                cashAccountId: $account->id,
                amount: '300.00',
                paymentMethod: 'cash',
                idempotencyKey: (string) Str::uuid(),
                actor: $this->accountant,
                // allocations omitted entirely — the exact call shape Phase
                // 1C now closes for a clean multi-item invoice.
            );
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
        }

        $this->assertSame(0, InvoicePayment::count(), 'no InvoicePayment was written');
        $this->assertSame(0, PaymentAllocation::count(), 'no PaymentAllocation was written');
        $this->assertSame(0, CashTransaction::where('cash_account_id', $account->id)->where('type', CashTransaction::TYPE_IN)->count(), 'no incoming CashTransaction was written');
        $this->assertSame($balanceBefore, (string) $account->fresh()->balance, 'cash account balance is unchanged');
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    /** The same central proof, but for an explicitly empty allocations array. */
    public function test_clean_multi_item_invoice_with_empty_allocations_is_rejected_atomically(): void
    {
        [$invoice, , ] = $this->issueCleanMultiItemInvoice('EmptyReject');
        $account = $this->cashAccount();
        $balanceBefore = (string) $account->fresh()->balance;

        try {
            app(InvoicePaymentService::class)->record(
                invoiceId: $invoice->id,
                cashAccountId: $account->id,
                amount: '300.00',
                paymentMethod: 'cash',
                idempotencyKey: (string) Str::uuid(),
                actor: $this->accountant,
                allocations: [],
            );
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
        }

        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, PaymentAllocation::count());
        $this->assertSame(0, CashTransaction::where('cash_account_id', $account->id)->where('type', CashTransaction::TYPE_IN)->count());
        $this->assertSame($balanceBefore, (string) $account->fresh()->balance);
    }

    /**
     * Grandfather exception A — historical/unallocated payment. Once a
     * multi-item invoice already carries a payment with no allocation rows
     * (characterized directly, never inferred), a subsequent legitimate
     * payment with allocations omitted must still succeed unallocated —
     * exactly Phase 1A's original behavior, never backfilled or blocked.
     */
    public function test_historical_ambiguous_invoice_still_accepts_a_null_allocation_payment(): void
    {
        [$invoice, , ] = $this->issueCleanMultiItemInvoice('HistoricalGrandfather');
        $account = $this->cashAccount();

        $this->insertLegacyUnallocatedPayment($invoice, $account, '300.00');
        $this->assertSame(0, PaymentAllocation::count(), 'the legacy row carries no allocation, by construction — never backfilled');

        $repayment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $account->id,
            amount: '200.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $this->assertSame('200.00', (string) $repayment->amount);
        $this->assertCount(0, $repayment->allocations, 'zero allocations created for the new compatibility payment');
        $this->assertSame(0, PaymentAllocation::count(), 'still zero allocations invoice-wide — nothing was backfilled');
        $this->assertSame(2, InvoicePayment::count());
    }

    /**
     * Grandfather exception B — refund-driven ambiguity. A fully allocated
     * payment, once refunded, makes the invoice's PaymentAllocation sums
     * gross and permanently untrustworthy (no payment_refund_allocations
     * table — Phase 1D, out of scope). A subsequent legitimate payment with
     * allocations omitted must still succeed unallocated.
     */
    public function test_refund_ambiguous_invoice_still_accepts_a_null_allocation_payment(): void
    {
        [$invoice, $itemA, ] = $this->issueCleanMultiItemInvoice('RefundGrandfather');
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
        $this->assertFalse(app(InvoicePaymentService::class)->isAllocationClean($invoice->fresh()));

        $repayment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $account->id,
            amount: '100.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $this->assertSame('100.00', (string) $repayment->amount);
        $this->assertCount(0, $repayment->allocations, 'zero allocations created for the compatibility repayment');
        $this->assertSame(1, PaymentAllocation::count(), 'only the original, pre-refund allocation exists — nothing new was written');
    }

    /**
     * Idempotency must be unaffected by Phase 1C: a retry using an
     * idempotency key that already belongs to a successful payment must
     * replay that exact payment, even if the invoice's allocation state
     * changed in the meantime (here: a refund happened after the original
     * payment, which would make a *fresh* explicit-allocation attempt
     * against this invoice illegal — but a retry of the original call must
     * never re-run that validation at all).
     */
    public function test_idempotent_retry_replays_the_original_payment_even_after_the_invoice_becomes_ambiguous(): void
    {
        [$invoice, $itemA, $itemB] = $this->issueCleanMultiItemInvoice('IdempotentRetry');
        $account = $this->cashAccount();
        $key = (string) Str::uuid();

        $original = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $account->id, amount: '500.00',
            paymentMethod: 'cash', idempotencyKey: $key, actor: $this->accountant,
            allocations: [['invoice_item_id' => $itemA->id, 'amount' => '500.00']],
        );

        // Change the invoice's allocation state after the original payment
        // — a refund makes it allocation-ambiguous.
        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $original->id, amount: '100.00', reason: 'test',
            idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        // Retrying with the exact same call (same idempotency key, amount,
        // invoice, cash account, payment method, installment) must simply
        // replay the original payment — never re-validate allocations
        // against the now-changed state, and never fail.
        $retry = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $account->id, amount: '500.00',
            paymentMethod: 'cash', idempotencyKey: $key, actor: $this->accountant,
            allocations: [['invoice_item_id' => $itemA->id, 'amount' => '500.00']],
        );

        $this->assertSame($original->id, $retry->id);
        $this->assertSame(1, InvoicePayment::count(), 'the retry created no second payment');
        $this->assertSame(1, PaymentAllocation::count(), 'the retry created no second allocation');
    }

    /** Normal-path regression: single-item invoice auto-allocation is untouched by Phase 1C. */
    public function test_single_item_invoice_still_auto_allocates_with_null_allocations(): void
    {
        $student = $this->enrolledStudent(suffix: 'SingleItemRegression');
        $fee = $this->registrationFee('500.00');
        $account = $this->cashAccount();

        $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'fees' => [$fee->id],
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::where('student_id', $student->id)->sole();

        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $account->id, amount: '500.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
        );

        $this->assertCount(1, $payment->allocations);
        $this->assertSame($invoice->items->sole()->id, $payment->allocations->first()->invoice_item_id);
        $this->assertSame('500.00', (string) $payment->allocations->first()->amount);
    }

    /** Normal-path regression: explicit allocations against a clean multi-item invoice still work. */
    public function test_clean_multi_item_invoice_with_explicit_allocations_still_succeeds(): void
    {
        [$invoice, $itemA, $itemB] = $this->issueCleanMultiItemInvoice('ExplicitStillWorks');
        $account = $this->cashAccount();

        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id, cashAccountId: $account->id, amount: '500.00',
            paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(), actor: $this->accountant,
            allocations: [['invoice_item_id' => $itemA->id, 'amount' => '300.00'], ['invoice_item_id' => $itemB->id, 'amount' => '200.00']],
        );

        $this->assertCount(2, $payment->allocations);
        $this->assertSame(2, PaymentAllocation::count());
    }
}
