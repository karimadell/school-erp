<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashSession;
use App\Models\CashTransaction;
use App\Models\InvoicePayment;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 3 — cash-drawer session (кассовая смена) lifecycle.
 *
 * Proves the strict cash-session rule (no cash movement outside an open shift),
 * opening-baseline provenance, FK-at-creation attribution, expected-cash
 * reconciliation, variance handling with authorisation + reason, and the
 * immutability of a closed session. The shared FinanceOperationsTestCase opens
 * a session on $this->cash in setUp; tests needing the no-session path use a
 * fresh drawer or closeCashSession().
 */
class CashSessionTest extends FinanceOperationsTestCase
{
    private function service(): CashSessionService
    {
        return app(CashSessionService::class);
    }

    private function freshDrawer(string $balance = '0.00'): CashAccount
    {
        return CashAccount::create(['name' => 'Резервная касса', 'type' => 'cash', 'balance' => $balance, 'is_active' => true]);
    }

    private function financeAdmin(): User
    {
        // school-admin holds every cash-session permission, incl. variance.
        return $this->user('school-admin');
    }

    private function pay(string $amount, string $method = 'cash', ?CashAccount $account = null): InvoicePayment
    {
        return app(InvoicePaymentService::class)->record(
            invoiceId: $this->invoice()->id,
            cashAccountId: ($account ?? $this->cash)->id,
            amount: $amount,
            paymentMethod: $method,
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );
    }

    // ----- Opening -------------------------------------------------------

    public function test_first_session_opens_from_trusted_account_balance(): void
    {
        $drawer = $this->freshDrawer('750.00');

        $session = $this->service()->open($drawer, $this->accountant);

        $this->assertSame('750.00', $session->opening_expected);
        $this->assertSame(CashSession::SOURCE_ACCOUNT_BALANCE, $session->opening_expected_source);
        $this->assertSame($this->accountant->id, $session->opened_by);
        $this->assertTrue($session->isOpen());
    }

    public function test_later_session_opens_from_previous_closed_session_balance(): void
    {
        $drawer = $this->freshDrawer('100.00');

        // Close the first shift with a counted total that deliberately differs
        // from the account balance (a variance, authorised) so the next open
        // provably reads the previous counted total, not the account balance.
        $first = $this->service()->open($drawer, $this->accountant);
        $this->service()->close($first, $this->financeAdmin(), '640.00', 'Пересчёт кассы');

        $second = $this->service()->open($drawer, $this->accountant);

        $this->assertSame('640.00', $second->opening_expected);
        $this->assertSame(CashSession::SOURCE_PREVIOUS_SESSION, $second->opening_expected_source);
        $this->assertSame('100.00', bcadd((string) $drawer->fresh()->balance, '0', 2)); // balance untouched
    }

    public function test_cannot_open_duplicate_active_session(): void
    {
        // $this->cash already has an open session from setUp.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service()->open($this->cash, $this->accountant);
    }

    public function test_cannot_open_session_on_bank_account(): void
    {
        $bank = CashAccount::create(['name' => 'Банк', 'type' => 'bank', 'balance' => '0.00', 'is_active' => true]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service()->open($bank, $this->accountant);
    }

    // ----- Strict cash-session rule --------------------------------------

    public function test_cash_payment_requires_open_session(): void
    {
        $drawer = $this->freshDrawer(); // no session

        try {
            $this->pay('100.00', 'cash', $drawer);
            $this->fail('Cash payment without an open session should be rejected.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('payment_method', $e->errors());
        }

        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, CashTransaction::where('cash_account_id', $drawer->id)->count());
        $this->assertSame('0.00', bcadd((string) $drawer->fresh()->balance, '0', 2));
    }

    public function test_non_cash_payment_does_not_require_a_session(): void
    {
        $drawer = $this->freshDrawer(); // no session

        $payment = $this->pay('100.00', 'bank', $drawer);

        $this->assertSame('100.00', $payment->amount);
        $tx = CashTransaction::where('cash_account_id', $drawer->id)->sole();
        $this->assertNull($tx->cash_session_id);
    }

    public function test_new_cash_transaction_is_linked_to_active_session_by_fk(): void
    {
        $payment = $this->pay('300.00');

        $tx = $payment->cashTransaction;
        $this->assertNotNull($tx->cash_session_id);
        $this->assertSame($this->cashSession->id, $tx->cash_session_id);
        $this->assertSame($this->accountant->id, $tx->created_by);
    }

    public function test_charge_and_collect_cash_requires_open_session(): void
    {
        $drawer = $this->freshDrawer(); // no session

        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.charge.store', $this->student), [
                'academic_year_id' => $this->year->id,
                'fee_id' => $this->fee->id,
                'quantity' => 1,
                'payment_period' => 'yearly',
                'due_date' => '2027-01-01',
                'pricing_date' => '2026-09-01',
                'idempotency_key' => (string) Str::uuid(),
                'collect_amount' => '1200.00',
                'payment_method' => 'cash',
                'cash_account_id' => $drawer->id,
            ])
            ->assertSessionHasErrors('payment_method');

        // All-or-nothing: no invoice, no payment, no cash movement.
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, CashTransaction::count());
    }

    public function test_historical_cash_transactions_are_not_backfilled(): void
    {
        // A session-less movement recorded before Phase 3 stays session-less.
        $historical = CashTransaction::create([
            'cash_account_id' => $this->cash->id,
            'amount' => '50.00',
            'type' => CashTransaction::TYPE_IN,
            'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Историческое движение',
        ]);

        $this->pay('300.00'); // a new, attributed collection into the same open session

        $this->assertNull($historical->fresh()->cash_session_id);
        $this->assertSame(0, $this->cashSession->transactions()->where('id', $historical->id)->count());
    }

    // ----- Expected cash + reconciliation --------------------------------

    public function test_expected_closing_balance_is_opening_plus_cash_in_minus_cash_out(): void
    {
        $drawer = $this->freshDrawer('200.00');
        $session = $this->service()->open($drawer, $this->accountant); // opening 200

        // A cash inflow into this session's drawer.
        $payment = $this->pay('300.00', 'cash', $drawer);
        // A refund outflow from the same drawer (attaches to the active session).
        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id,
            amount: '50.00',
            reason: 'Частичный возврат',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $session->refresh();
        $this->assertSame('300.00', $session->cashIn());
        $this->assertSame('50.00', $session->cashOut());
        $this->assertSame('450.00', $session->expectedClosing()); // 200 + 300 − 50
    }

    public function test_refund_cash_outflow_attaches_to_active_session(): void
    {
        $payment = $this->pay('300.00'); // into $this->cash open session

        $refund = app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id,
            amount: '100.00',
            reason: 'Возврат',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $this->assertSame($this->cashSession->id, $refund->cashTransaction->cash_session_id);
        $this->assertSame($this->accountant->id, $refund->cashTransaction->created_by);
    }

    public function test_cash_refund_without_open_session_is_rejected(): void
    {
        $payment = $this->pay('300.00'); // into $this->cash (open session)
        $this->closeCashSession(); // drawer now has no open session

        try {
            app(InvoiceRefundService::class)->refund(
                invoicePaymentId: $payment->id,
                amount: '100.00',
                reason: 'Возврат',
                idempotencyKey: (string) Str::uuid(),
                actor: $this->accountant,
            );
            $this->fail('A cash refund without an open session should be rejected.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('cash_account_id', $e->errors());
        }

        // Atomic rollback: no refund record, no refund cash movement, the
        // original payment is untouched, and the drawer balance is unchanged
        // (only the 300.00 income remains).
        $this->assertSame(0, PaymentRefund::count());
        $this->assertSame(0, CashTransaction::where('category', CashTransaction::CATEGORY_REFUND)->count());
        $this->assertSame('300.00', $payment->fresh()->amount);
        $this->assertSame('300.00', bcadd((string) $this->cash->fresh()->balance, '0', 2));
    }

    public function test_non_cash_refund_does_not_require_a_cash_session(): void
    {
        // A bank account is not a physical drawer: neither the non-cash payment
        // nor its refund needs an open session.
        $bank = CashAccount::create(['name' => 'Банк', 'type' => 'bank', 'balance' => '0.00', 'is_active' => true]);

        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $this->invoice()->id,
            cashAccountId: $bank->id,
            amount: '300.00',
            paymentMethod: 'bank',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $refund = app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id,
            amount: '100.00',
            reason: 'Возврат',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $this->assertSame(1, PaymentRefund::count());
        $this->assertNull($refund->cashTransaction->cash_session_id);
    }

    // ----- Closing -------------------------------------------------------

    public function test_normal_close_with_zero_variance(): void
    {
        $drawer = $this->freshDrawer('0.00');
        $session = $this->service()->open($drawer, $this->accountant);
        $this->pay('500.00', 'cash', $drawer); // expected now 500

        $closed = $this->service()->close($session, $this->accountant, '500.00');

        $this->assertTrue($closed->isClosed());
        $this->assertSame('500.00', $closed->expected_cash);
        $this->assertSame('500.00', $closed->closing_counted);
        $this->assertSame('0.00', $closed->variance);
        $this->assertSame($this->accountant->id, $closed->closed_by);
        $this->assertNotNull($closed->closed_at);
    }

    public function test_close_with_shortage_records_negative_variance(): void
    {
        $drawer = $this->freshDrawer('0.00');
        $session = $this->service()->open($drawer, $this->accountant);
        $this->pay('500.00', 'cash', $drawer);

        $admin = $this->financeAdmin();
        $closed = $this->service()->close($session, $admin, '480.00', 'Недостача в кассе');

        $this->assertSame('-20.00', $closed->variance);
        $this->assertSame('Недостача в кассе', $closed->close_note);
    }

    public function test_close_with_overage_records_positive_variance(): void
    {
        $drawer = $this->freshDrawer('0.00');
        $session = $this->service()->open($drawer, $this->accountant);
        $this->pay('500.00', 'cash', $drawer);

        $admin = $this->financeAdmin();
        $closed = $this->service()->close($session, $admin, '530.00', 'Излишек');

        $this->assertSame('30.00', $closed->variance);
    }

    public function test_variance_close_requires_a_reason(): void
    {
        $drawer = $this->freshDrawer('0.00');
        $session = $this->service()->open($drawer, $this->accountant);
        $this->pay('500.00', 'cash', $drawer);

        $admin = $this->financeAdmin();
        try {
            $this->service()->close($session, $admin, '480.00'); // no note
            $this->fail('A variance close without a reason should be rejected.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('close_note', $e->errors());
        }

        $this->assertTrue($session->fresh()->isOpen()); // still open
    }

    public function test_unauthorized_variance_close_is_denied(): void
    {
        $drawer = $this->freshDrawer('0.00');
        $session = $this->service()->open($drawer, $this->accountant);
        $this->pay('500.00', 'cash', $drawer);

        // Accountant lacks 'close cash sessions with variance'.
        try {
            $this->service()->close($session, $this->accountant, '480.00', 'Недостача');
            $this->fail('An unauthorized variance close should be denied.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('closing_counted', $e->errors());
        }

        $this->assertTrue($session->fresh()->isOpen());
    }

    public function test_accountant_can_close_with_zero_variance_without_the_variance_permission(): void
    {
        $this->assertFalse($this->accountant->can('close cash sessions with variance'));

        $drawer = $this->freshDrawer('0.00');
        $session = $this->service()->open($drawer, $this->accountant);
        $this->pay('500.00', 'cash', $drawer);

        $closed = $this->service()->close($session, $this->accountant, '500.00');
        $this->assertTrue($closed->isClosed());
    }

    // ----- Immutability --------------------------------------------------

    public function test_closed_session_is_immutable(): void
    {
        $drawer = $this->freshDrawer('0.00');
        $session = $this->service()->open($drawer, $this->accountant);
        $closed = $this->service()->close($session, $this->accountant, '0.00');

        $this->expectException(RuntimeException::class);
        $closed->forceFill(['open_note' => 'подделка'])->save();
    }

    public function test_closed_session_cannot_be_reopened(): void
    {
        $drawer = $this->freshDrawer('0.00');
        $session = $this->service()->open($drawer, $this->accountant);
        $closed = $this->service()->close($session, $this->accountant, '0.00');

        $this->expectException(RuntimeException::class);
        $closed->forceFill(['status' => CashSession::STATUS_OPEN])->save();
    }

    public function test_closing_an_already_closed_session_is_rejected_by_the_service(): void
    {
        $drawer = $this->freshDrawer('0.00');
        $session = $this->service()->open($drawer, $this->accountant);
        $this->service()->close($session, $this->accountant, '0.00');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service()->close($session, $this->accountant, '0.00');
    }

    // ----- Authorization (routes) ----------------------------------------

    public function test_reception_cannot_view_or_open_sessions(): void
    {
        $reception = $this->user('reception');

        $this->actingAs($reception)->get(route('dashboard.cash.sessions.index'))->assertForbidden();
        $this->actingAs($reception)->get(route('dashboard.cash.sessions.create'))->assertForbidden();
        $this->actingAs($reception)
            ->post(route('dashboard.cash.sessions.store'), ['cash_account_id' => $this->cash->id])
            ->assertForbidden();
    }

    public function test_authorized_user_can_open_and_view_sessions_via_http(): void
    {
        $drawer = $this->freshDrawer('0.00');

        $this->actingAs($this->accountant)
            ->post(route('dashboard.cash.sessions.store'), ['cash_account_id' => $drawer->id])
            ->assertRedirect();

        $session = CashSession::where('cash_account_id', $drawer->id)->sole();
        $this->actingAs($this->accountant)
            ->get(route('dashboard.cash.sessions.show', $session))
            ->assertOk()
            ->assertSee('Кассовая смена');
    }
}
