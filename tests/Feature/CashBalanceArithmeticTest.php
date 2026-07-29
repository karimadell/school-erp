<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\CashTransfer;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
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

    protected function invoiceManager(): User
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'manage invoices']);
        Permission::firstOrCreate(['name' => 'view invoices']);
        $user->givePermissionTo('manage invoices', 'view invoices');

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
        $invoice = $this->makeInvoice($account, total: 1000, paid: 0);

        $response = $this->actingAs($user)->post(route('dashboard.invoices.pay', $invoice), [
            'amount' => 300,
            'cash_account_id' => $account->id,
            'payment_method' => 'cash',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('800.00', $account->fresh()->balance);
    }

    public function test_a_payment_is_posted_once_and_does_not_double_count(): void
    {
        $user = $this->invoiceManager();
        $account = $this->makeCashAccount(balance: 0);
        $invoice = $this->makeInvoice($account, total: 1000, paid: 0);

        $this->actingAs($user)->post(route('dashboard.invoices.pay', $invoice), [
            'amount' => 250,
            'cash_account_id' => $account->id,
            'payment_method' => 'cash',
        ]);

        // Exactly one ledger row and exactly one balance movement — not
        // 500 (the old, doubled behavior).
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
        $user = $this->invoiceManager();
        $account = $this->makeCashAccount(balance: 1000);
        $invoice = $this->makeInvoice($account, total: 1000, paid: 400);

        $response = $this->actingAs($user)->post(route('dashboard.invoices.refund', $invoice), [
            'amount' => 150,
            'cash_account_id' => $account->id,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('850.00', $account->fresh()->balance);
    }

    public function test_a_refund_is_posted_once_and_does_not_double_count(): void
    {
        $user = $this->invoiceManager();
        $account = $this->makeCashAccount(balance: 1000);
        $invoice = $this->makeInvoice($account, total: 1000, paid: 600);

        $this->actingAs($user)->post(route('dashboard.invoices.refund', $invoice), [
            'amount' => 200,
            'cash_account_id' => $account->id,
        ]);

        $this->assertSame(1, CashTransaction::where('cash_account_id', $account->id)->where('type', 'out')->count());
        // 1000 - 200 (not 1000 - 400, which the old doubled bug produced).
        $this->assertSame('800.00', $account->fresh()->balance);
    }

    /*
    |--------------------------------------------------------------------------
    | Cash transfer
    |--------------------------------------------------------------------------
    */

    public function test_a_transfer_moves_exactly_the_transfer_amount_between_accounts(): void
    {
        $user = User::factory()->create();
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
        $user = User::factory()->create();
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
        $user = User::factory()->create();
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
        $user = User::factory()->create();
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
