<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\CashTransfer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use App\Services\Finance\InvoicePaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Finance Batch 1 / Cash balance arithmetic correctness
 * (InvoiceController::recordInvoicePayment()/refund(), Cash\CashTransferController::store()):
 * each of these three write paths used to mutate CashAccount.balance
 * directly AND create a CashTransaction row — but CashTransaction's own
 * created-event hook (CashTransaction::booted()) already adjusts balance
 * for every row it creates, so every payment/refund/transfer was posted
 * twice against the account balance. The fix removes the redundant direct
 * mutation at each of the three call sites, leaving the model event as
 * the single source of truth (already the correct, un-doubled pattern
 * Cash\CashTransactionController::storeIncome()/storeExpense() used all
 * along). These tests assert the corrected, single-posting arithmetic.
 */
class CashBalanceArithmeticTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Active + administrative role ('reception') clears
     * EnsureAdministrativePortalAccess; the explicit permissions below are
     * what actually authorize each finance action.
     */
    protected function invoiceManager(): User
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('reception');
        foreach (['manage invoices', 'view invoices', 'refund payments'] as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
        // 'refund payments' is the canonical refund endpoint's own permission
        // (FinanceOperationsController::storeRefund) — separate from 'manage
        // invoices' so refund authority is grantable independently (ADR-008 era).
        $user->givePermissionTo('manage invoices', 'view invoices', 'refund payments');

        return $user;
    }

    /**
     * Phase 3: cash payments/refunds require an open shift on the drawer.
     * Opening it via the service is the established finance-test pattern
     * (see FinanceOperationsTestCase) and creates no CashTransaction of its
     * own, so single-posting assertions stay exact.
     */
    protected function openCashSession(CashAccount $account, User $actor): void
    {
        app(CashSessionService::class)->open($account, $actor);
    }

    /**
     * Record a real cash payment through the canonical service so a refund
     * has a genuine InvoicePayment to reverse.
     */
    protected function recordCashPayment(Invoice $invoice, CashAccount $account, User $actor, string $amount, string $idempotencyKey): InvoicePayment
    {
        return app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $account->id,
            paymentMethod: 'cash',
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            actor: $actor,
        );
    }

    protected function cashManager(): User
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('reception');
        Permission::firstOrCreate(['name' => 'manage cash']);
        $user->givePermissionTo('manage cash');

        return $user;
    }

    protected function makeCashAccount(float $balance = 0): CashAccount
    {
        return CashAccount::create(['name' => 'Account ' . uniqid(), 'type' => CashAccount::TYPE_CASH, 'balance' => $balance]);
    }

    protected function makeInvoice(CashAccount $account, float $total, float $paid): Invoice
    {
        $student = Student::create(['name' => 'Test Student']);

        return Invoice::create([
            'student_id' => $student->id,
            'cash_account_id' => $account->id,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'remaining_amount' => max($total - $paid, 0),
            'status' => $paid <= 0 ? Invoice::STATUS_UNPAID : ($paid < $total ? Invoice::STATUS_PARTIAL : Invoice::STATUS_PAID),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Payment posting
    |--------------------------------------------------------------------------
    */

    public function test_a_payment_increases_the_account_by_exactly_the_payment_amount(): void
    {
        $user = $this->invoiceManager();
        $account = $this->makeCashAccount(balance: 500);
        $this->openCashSession($account, $user);
        $invoice = $this->makeInvoice($account, total: 1000, paid: 0);

        $response = $this->actingAs($user)->post(route('dashboard.invoices.pay', $invoice), [
            'amount' => 300,
            'cash_account_id' => $account->id,
            'payment_method' => 'cash',
            // ADR-008: every money write requires an idempotency key (uuid).
            // Deterministic literal so the test posts a single, well-formed key.
            'idempotency_key' => '11111111-1111-4111-8111-111111111111',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('800.00', $account->fresh()->balance);
    }

    public function test_a_payment_is_posted_once_and_does_not_double_count(): void
    {
        $user = $this->invoiceManager();
        $account = $this->makeCashAccount(balance: 0);
        $this->openCashSession($account, $user);
        $invoice = $this->makeInvoice($account, total: 1000, paid: 0);

        $this->actingAs($user)->post(route('dashboard.invoices.pay', $invoice), [
            'amount' => 250,
            'cash_account_id' => $account->id,
            'payment_method' => 'cash',
            // ADR-008: idempotency key (uuid) required on every money write.
            'idempotency_key' => '22222222-2222-4222-8222-222222222222',
        ]);

        // Exactly one ledger row and exactly one balance movement — not
        // 500 (the old, doubled behavior). Opening the shift adds no
        // CashTransaction, so this count reflects the single payment only.
        $this->assertSame(1, CashTransaction::where('cash_account_id', $account->id)->count());
        $this->assertSame('250.00', $account->fresh()->balance);
    }

    /*
    |--------------------------------------------------------------------------
    | Refund posting
    |--------------------------------------------------------------------------
    */

    public function test_a_refund_decreases_the_account_by_exactly_the_refund_amount(): void
    {
        // The legacy invoices.refund path is disabled (410); the canonical
        // workflow refunds a specific InvoicePayment. Arithmetic intent is
        // preserved: a refund lowers the drawer by exactly the refunded amount.
        $user = $this->invoiceManager();
        $account = $this->makeCashAccount(balance: 0);
        $this->openCashSession($account, $user);
        $invoice = $this->makeInvoice($account, total: 1000, paid: 0);

        $payment = $this->recordCashPayment($invoice, $account, $user, '400.00', '33333333-3333-4333-8333-333333333333');
        $this->assertSame('400.00', $account->fresh()->balance);

        $response = $this->actingAs($user)->post(route('dashboard.payments.refund.store', $payment), [
            'amount' => 150,
            'reason' => 'Частичный возврат',
            'idempotency_key' => '44444444-4444-4444-8444-444444444444',
        ]);

        $response->assertSessionHasNoErrors();
        // 400 - 150: decreased by exactly the refund amount.
        $this->assertSame('250.00', $account->fresh()->balance);
    }

    public function test_a_refund_is_posted_once_and_does_not_double_count(): void
    {
        $user = $this->invoiceManager();
        $account = $this->makeCashAccount(balance: 0);
        $this->openCashSession($account, $user);
        $invoice = $this->makeInvoice($account, total: 1000, paid: 0);

        $payment = $this->recordCashPayment($invoice, $account, $user, '600.00', '55555555-5555-4555-8555-555555555555');

        $this->actingAs($user)->post(route('dashboard.payments.refund.store', $payment), [
            'amount' => 200,
            'reason' => 'Возврат',
            'idempotency_key' => '66666666-6666-4666-8666-666666666666',
        ]);

        // Exactly one 'out' movement for the refund (the payment itself is
        // 'in'), and the balance dropped by exactly 200 — not doubled.
        $this->assertSame(1, CashTransaction::where('cash_account_id', $account->id)->where('type', 'out')->count());
        // 600 - 200 (not 600 - 400, which the old doubled bug produced).
        $this->assertSame('400.00', $account->fresh()->balance);
    }

    /*
    |--------------------------------------------------------------------------
    | Cash transfer
    |--------------------------------------------------------------------------
    */

    public function test_a_transfer_moves_exactly_the_transfer_amount_between_accounts(): void
    {
        $user = $this->cashManager();
        $from = $this->makeCashAccount(balance: 1000);
        $to = $this->makeCashAccount(balance: 200);

        $response = $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 300,
            'purpose' => 'Test transfer',
            'notes' => '',
        ]);

        $response->assertRedirect(route('dashboard.cash.transfers'));
        $this->assertSame('700.00', $from->fresh()->balance);
        $this->assertSame('500.00', $to->fresh()->balance);
    }

    public function test_a_transfer_preserves_the_combined_total_of_both_accounts(): void
    {
        $user = $this->cashManager();
        $from = $this->makeCashAccount(balance: 1000);
        $to = $this->makeCashAccount(balance: 200);
        $combinedBefore = (float) $from->balance + (float) $to->balance;

        $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 300,
            'purpose' => 'Test transfer',
            'notes' => '',
        ]);

        $combinedAfter = (float) $from->fresh()->balance + (float) $to->fresh()->balance;

        $this->assertSame($combinedBefore, $combinedAfter);
    }

    public function test_a_transfer_is_posted_once_per_side_and_does_not_double_count(): void
    {
        $user = $this->cashManager();
        $from = $this->makeCashAccount(balance: 1000);
        $to = $this->makeCashAccount(balance: 0);

        $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 400,
            'purpose' => 'Test transfer',
            'notes' => '',
        ]);

        $this->assertSame(1, CashTransaction::where('cash_account_id', $from->id)->where('type', 'out')->count());
        $this->assertSame(1, CashTransaction::where('cash_account_id', $to->id)->where('type', 'in')->count());
        // 1000 - 400 (not 1000 - 800, which the old doubled bug produced).
        $this->assertSame('600.00', $from->fresh()->balance);
        // 0 + 400 (not 0 + 800).
        $this->assertSame('400.00', $to->fresh()->balance);
    }

    /*
    |--------------------------------------------------------------------------
    | Rollback on failure
    |--------------------------------------------------------------------------
    */

    public function test_a_transfer_rejected_for_insufficient_balance_rolls_back_completely(): void
    {
        $user = $this->cashManager();
        $from = $this->makeCashAccount(balance: 50);
        $to = $this->makeCashAccount(balance: 0);

        $response = $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 500,
            'purpose' => 'Test transfer',
            'notes' => '',
        ]);

        $response->assertStatus(422);

        // Neither account moved, and no CashTransfer/CashTransaction rows
        // were left behind by the aborted transaction.
        $this->assertSame('50.00', $from->fresh()->balance);
        $this->assertSame('0.00', $to->fresh()->balance);
        $this->assertSame(0, CashTransfer::count());
        $this->assertSame(0, CashTransaction::count());
    }
}
