<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\Invoice;
use App\Services\Finance\CashSessionService;
use App\Services\Finance\CashTransferService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\StudentFinanceSummaryService;
use Illuminate\Support\Str;

/**
 * Finance V2, Phase 0 — safety/invariant/regression baseline.
 *
 * Per docs/finance-v2-architecture.md §19 Phase 0: before any Finance V2
 * change lands, these tests lock in that the CURRENT accounting invariants
 * hold. No behavior is changed by this file — it is pure verification of
 * existing code, and becomes the permanent regression floor every later
 * phase is checked against (re-run at the end of every subsequent phase).
 */
class FinanceV2Phase0BaselineTest extends FinanceOperationsTestCase
{
    /**
     * Invariant 1: CashAccount.balance always equals the net (in − out) of
     * its own CashTransaction rows — the increment/decrement hooks in
     * CashTransaction::booted() must never drift from reality.
     */
    public function test_cash_account_balance_equals_the_net_of_its_own_cash_transactions(): void
    {
        $invoice = $this->invoice('1000.00');
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            amount: '600.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        Expense::create([
            'title' => 'Тестовый расход',
            'amount' => '150.00',
            'category' => 'other',
            'expense_date' => now()->toDateString(),
            'cash_account_id' => $this->cash->id,
        ]);

        $netFromLedger = CashTransaction::where('cash_account_id', $this->cash->id)
            ->where('type', CashTransaction::TYPE_IN)->sum('amount')
            - CashTransaction::where('cash_account_id', $this->cash->id)
                ->where('type', CashTransaction::TYPE_OUT)->sum('amount');

        $this->assertSame('450.00', (string) $this->cash->fresh()->balance);
        $this->assertSame(450.0, (float) $netFromLedger);
    }

    /**
     * Invariant 2: every InvoicePayment.amount matches the amount of its
     * own linked CashTransaction exactly — the two must never diverge.
     */
    public function test_invoice_payment_amount_matches_its_own_cash_transaction_amount(): void
    {
        $invoice = $this->invoice('800.00');
        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            amount: '325.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $this->assertSame('325.00', (string) $payment->amount);
        $this->assertNotNull($payment->cashTransaction);
        $this->assertSame((string) $payment->amount, (string) $payment->cashTransaction->amount);
        $this->assertSame($payment->id, $payment->cashTransaction->invoice_payment_id);
    }

    /**
     * Invariant 3: an internal transfer between two cash accounts is never
     * counted as income or expense in the cash report totals — confirmed
     * against Cash\CashTransactionController::reports(), the endpoint whose
     * excludeInternalTransfers() is the intentional, correct implementation
     * of this rule.
     *
     * NOTE (Phase 0 finding, not fixed here): the near-duplicate
     * CashReportController@index (route cash.reports.index) computes its
     * totals directly from an unfiltered transaction collection and does
     * NOT exclude transfers — already flagged in
     * docs/finance-v2-architecture.md §2/§3 for later consolidation. This
     * test intentionally targets only the endpoint with the correct,
     * intentional exclusion.
     */
    public function test_internal_transfers_are_excluded_from_cash_report_income_and_expense_totals(): void
    {
        $this->accountant->givePermissionTo('view cash reports');
        $secondAccount = CashAccount::create([
            'name' => 'Второй счёт (Phase 0 baseline)',
            'type' => CashAccount::TYPE_CASH,
            'balance' => '0.00',
            'is_active' => true,
        ]);
        app(CashSessionService::class)->open($secondAccount, $this->accountant);

        $invoice = $this->invoice('1000.00');
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            amount: '1000.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        app(CashTransferService::class)->transfer(
            fromAccountId: $this->cash->id,
            toAccountId: $secondAccount->id,
            amount: '400.00',
            purpose: 'Phase 0 baseline test transfer',
            notes: null,
            actor: $this->accountant,
        );

        $response = $this->actingAs($this->accountant)->get(route('dashboard.cash.reports'));

        // The real income (1000) must be the reported total-in; the 400
        // transfer leg posted as 'in' on the receiving account must not
        // inflate it to 1400. Asserted directly against the view data
        // (compact('totalIn', 'totalOut') in the controller) rather than
        // rendered HTML, so this stays exact regardless of number formatting.
        $response->assertOk()
            ->assertViewHas('totalIn', fn ($totalIn) => bccomp((string) $totalIn, '1000.00', 2) === 0)
            ->assertViewHas('totalOut', fn ($totalOut) => bccomp((string) $totalOut, '0.00', 2) === 0);
    }

    /**
     * Invariant 4: CashSession::expectedClosing() always equals
     * opening_expected + this session's own cash-in − this session's own
     * cash-out — exactly the formula the model itself documents.
     */
    public function test_cash_session_expected_closing_matches_hand_computed_math(): void
    {
        $opening = (string) $this->cashSession->opening_expected;

        $invoice = $this->invoice('700.00');
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            amount: '500.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        Expense::create([
            'title' => 'Тестовый расход в смене',
            'amount' => '120.00',
            'category' => 'other',
            'expense_date' => now()->toDateString(),
            'cash_account_id' => $this->cash->id,
        ]);

        $expectedByHand = bcsub(bcadd($opening, '500.00', 2), '120.00', 2);

        $this->assertSame($expectedByHand, $this->cashSession->fresh()->expectedClosing());
    }

    /**
     * Invariant 5: StudentFinanceSummaryService's totals match a
     * hand-computed sum of the student's own Invoice rows — the aggregator
     * must never drift from the obligations it's summarizing.
     */
    public function test_student_finance_summary_matches_invoice_totals_on_a_known_fixture(): void
    {
        $invoiceA = $this->invoice('1000.00');
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoiceA->id,
            cashAccountId: $this->cash->id,
            amount: '400.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $invoiceB = $this->invoice('500.00');
        // invoiceB stays fully unpaid.

        $summary = app(StudentFinanceSummaryService::class)->summarize($this->student->fresh());

        $this->assertSame('1500.00', $summary['gross_invoiced']);
        $this->assertSame('1100.00', $summary['gross_remaining']);
        $this->assertSame('1100.00', $summary['net_outstanding']);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoiceA->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoiceB->fresh()->status);
    }
}
