<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashSession;
use App\Models\CashTransaction;
use App\Models\CashTransfer;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use App\Services\Finance\CashTransferService;
use App\Services\Finance\EmployeePayrollService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Cash Operations Phase 1 — the canonical internal-transfer/handover/
 * owner-return workflow built on top of the pre-existing CashAccount /
 * CashTransaction / CashTransfer / CashSession foundation.
 */
class CashOperationsPhase1Test extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private CashTransferService $transfers;

    private CashSessionService $sessions;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');
        $this->transfers = app(CashTransferService::class);
        $this->sessions = app(CashSessionService::class);
    }

    // Resolved by canonical role, never by display name — see
    // CashAccount::operating()/etc. and the migration that introduced
    // the role column.
    private function operatingAccount(): CashAccount
    {
        return CashAccount::operating() ?? $this->fail('No canonical operating account resolved.');
    }

    private function ownerAccount(): CashAccount
    {
        return CashAccount::owner() ?? $this->fail('No canonical owner account resolved.');
    }

    private function bankAccount(): CashAccount
    {
        return CashAccount::bank() ?? $this->fail('No canonical bank account resolved.');
    }

    private function instapayAccount(): CashAccount
    {
        return CashAccount::instapay() ?? $this->fail('No canonical instapay account resolved.');
    }

    /*
    |--------------------------------------------------------------------------
    | 1-2. Handover / owner-return
    |--------------------------------------------------------------------------
    */

    public function test_migration_seeds_the_four_canonical_accounts_with_the_correct_types(): void
    {
        $this->assertSame(CashAccount::TYPE_CASH, $this->operatingAccount()->type);
        $this->assertSame(CashAccount::TYPE_OWNER_CASH, $this->ownerAccount()->type);
        $this->assertSame(CashAccount::TYPE_BANK, $this->bankAccount()->type);
        $this->assertSame(CashAccount::TYPE_INSTAPAY, $this->instapayAccount()->type);
        // Owner cash is physical cash but deliberately not a session-tracked
        // drawer (the owner doesn't run shifts) — see CashAccount's type
        // constants docblock.
        $this->assertFalse($this->ownerAccount()->isCashDrawer());
        $this->assertTrue($this->operatingAccount()->isCashDrawer());
    }

    public function test_operating_to_owner_handover_transfers_the_exact_amount(): void
    {
        $operating = $this->operatingAccount();
        $owner = $this->ownerAccount();
        $operating->forceFill(['balance' => '40000.00'])->save();
        $this->sessions->open($operating, $this->accountant);

        $transfer = $this->transfers->transfer(
            fromAccountId: $operating->id,
            toAccountId: $owner->id,
            amount: '35000.00',
            purpose: 'Передача дневной выручки владельцу',
            notes: null,
            actor: $this->accountant,
            transferType: CashTransfer::TYPE_HANDOVER,
        );

        $this->assertSame('5000.00', $operating->fresh()->balance);
        $this->assertSame('35000.00', $owner->fresh()->balance);
        $this->assertSame(CashTransfer::TYPE_HANDOVER, $transfer->transfer_type);
    }

    public function test_owner_to_operating_return_transfers_the_exact_amount(): void
    {
        $operating = $this->operatingAccount();
        $owner = $this->ownerAccount();
        $operating->forceFill(['balance' => '5000.00'])->save();
        $owner->forceFill(['balance' => '35000.00'])->save();
        $this->sessions->open($operating, $this->accountant);

        $transfer = $this->transfers->transfer(
            fromAccountId: $owner->id,
            toAccountId: $operating->id,
            amount: '30000.00',
            purpose: 'Пополнение операционной кассы',
            notes: null,
            actor: $this->accountant,
            transferType: CashTransfer::TYPE_OWNER_RETURN,
        );

        $this->assertSame('5000.00', $owner->fresh()->balance);
        $this->assertSame('35000.00', $operating->fresh()->balance);
        $this->assertSame(CashTransfer::TYPE_OWNER_RETURN, $transfer->transfer_type);
    }

    /*
    |--------------------------------------------------------------------------
    | 3-5. Accounting classification
    |--------------------------------------------------------------------------
    */

    public function test_a_handover_does_not_change_the_combined_total_of_both_accounts(): void
    {
        $operating = $this->operatingAccount();
        $owner = $this->ownerAccount();
        $operating->forceFill(['balance' => '40000.00'])->save();
        $this->sessions->open($operating, $this->accountant);
        $combinedBefore = bcadd($operating->balance, $owner->balance, 2);

        $this->transfers->transfer($operating->id, $owner->id, '35000.00', 'Передача выручки', null, $this->accountant, CashTransfer::TYPE_HANDOVER);

        $combinedAfter = bcadd($operating->fresh()->balance, $owner->fresh()->balance, 2);
        $this->assertSame($combinedBefore, $combinedAfter);
    }

    public function test_transfers_are_excluded_from_revenue_and_expense_totals(): void
    {
        $operating = $this->operatingAccount();
        $owner = $this->ownerAccount();
        $operating->forceFill(['balance' => '40000.00'])->save();
        $this->sessions->open($operating, $this->accountant);

        $this->transfers->transfer($operating->id, $owner->id, '35000.00', 'Передача выручки', null, $this->accountant, CashTransfer::TYPE_HANDOVER);

        $income = CashTransaction::query()->where('category', CashTransaction::CATEGORY_INCOME)->sum('amount');
        $expense = CashTransaction::query()->where('category', CashTransaction::CATEGORY_EXPENSE)->sum('amount');
        $this->assertSame(0.0, (float) $income);
        $this->assertSame(0.0, (float) $expense);

        // Both legs are tagged category=transfer, which
        // Cash\CashTransactionController::excludeInternalTransfers()
        // already strips out of operating in/out totals.
        $this->assertSame(2, CashTransaction::query()->where('category', CashTransaction::CATEGORY_TRANSFER)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | 6-8. Rejections
    |--------------------------------------------------------------------------
    */

    public function test_cannot_transfer_to_the_same_account(): void
    {
        $operating = $this->operatingAccount();
        $this->sessions->open($operating, $this->accountant);

        $this->expectException(ValidationException::class);
        $this->transfers->transfer($operating->id, $operating->id, '100.00', 'Test', null, $this->accountant);
    }

    public function test_cannot_transfer_a_zero_amount(): void
    {
        $operating = $this->operatingAccount();
        $owner = $this->ownerAccount();
        $this->sessions->open($operating, $this->accountant);

        $this->expectException(ValidationException::class);
        $this->transfers->transfer($operating->id, $owner->id, '0.00', 'Test', null, $this->accountant);
    }

    public function test_cannot_transfer_a_negative_amount(): void
    {
        $operating = $this->operatingAccount();
        $owner = $this->ownerAccount();
        $this->sessions->open($operating, $this->accountant);

        $this->expectException(ValidationException::class);
        $this->transfers->transfer($operating->id, $owner->id, '-50.00', 'Test', null, $this->accountant);
    }

    public function test_insufficient_balance_aborts_with_422_and_changes_nothing(): void
    {
        $operating = $this->operatingAccount();
        $owner = $this->ownerAccount();
        $operating->forceFill(['balance' => '100.00'])->save();
        $this->sessions->open($operating, $this->accountant);

        try {
            $this->transfers->transfer($operating->id, $owner->id, '5000.00', 'Test', null, $this->accountant);
            $this->fail('Expected a 422 HTTP exception for insufficient balance.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame('100.00', $operating->fresh()->balance);
        $this->assertSame('0.00', $owner->fresh()->balance);
        $this->assertSame(0, CashTransfer::count());
        $this->assertSame(0, CashTransaction::count());
    }

    /*
    |--------------------------------------------------------------------------
    | 9-10. Idempotency / replay
    |--------------------------------------------------------------------------
    */

    public function test_replaying_the_same_idempotency_key_does_not_post_a_second_transfer(): void
    {
        $operating = $this->operatingAccount();
        $owner = $this->ownerAccount();
        $operating->forceFill(['balance' => '40000.00'])->save();
        $this->sessions->open($operating, $this->accountant);
        $key = '99999999-9999-4999-8999-999999999999';

        $first = $this->transfers->transfer($operating->id, $owner->id, '35000.00', 'Передача выручки', null, $this->accountant, CashTransfer::TYPE_HANDOVER, $key);
        $second = $this->transfers->transfer($operating->id, $owner->id, '35000.00', 'Передача выручки', null, $this->accountant, CashTransfer::TYPE_HANDOVER, $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CashTransfer::count());
        $this->assertSame(2, CashTransaction::count()); // exactly one pair, not two
        $this->assertSame('5000.00', $operating->fresh()->balance);
        $this->assertSame('35000.00', $owner->fresh()->balance);
    }

    public function test_reusing_an_idempotency_key_for_a_different_payload_is_rejected(): void
    {
        $operating = $this->operatingAccount();
        $owner = $this->ownerAccount();
        $operating->forceFill(['balance' => '40000.00'])->save();
        $this->sessions->open($operating, $this->accountant);
        $key = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        $this->transfers->transfer($operating->id, $owner->id, '1000.00', 'Test', null, $this->accountant, idempotencyKey: $key);

        $this->expectException(ValidationException::class);
        // Same key, different amount — must not silently succeed or reuse.
        $this->transfers->transfer($operating->id, $owner->id, '2000.00', 'Test', null, $this->accountant, idempotencyKey: $key);
    }

    public function test_sequential_calls_with_the_same_key_leave_balances_consistent(): void
    {
        // Practical stand-in for concurrency safety in a single-threaded
        // test process: repeated calls with the same key must be provably
        // safe to retry (e.g. a double-click), which is exactly what a
        // concurrent retry would also produce given the row locks + the
        // idempotency recheck taken after locking in CashTransferService.
        $operating = $this->operatingAccount();
        $owner = $this->ownerAccount();
        $operating->forceFill(['balance' => '1000.00'])->save();
        $this->sessions->open($operating, $this->accountant);
        $key = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        for ($i = 0; $i < 5; $i++) {
            $this->transfers->transfer($operating->id, $owner->id, '400.00', 'Test', null, $this->accountant, idempotencyKey: $key);
        }

        $this->assertSame(1, CashTransfer::count());
        $this->assertSame('600.00', $operating->fresh()->balance);
        $this->assertSame('400.00', $owner->fresh()->balance);
    }

    /*
    |--------------------------------------------------------------------------
    | 17. Salary cash payment participates in expected cash
    |--------------------------------------------------------------------------
    */

    public function test_a_cash_salary_payment_reduces_expected_closing_cash_for_the_open_session(): void
    {
        $operating = $this->operatingAccount();
        $operating->forceFill(['balance' => '10000.00'])->save();
        $session = $this->sessions->open($operating, $this->accountant);

        $employee = User::factory()->create(['is_active' => true]);
        $employee->assignRole('reception');
        $this->accountant->givePermissionTo(['manage payroll', 'approve payroll', 'pay payroll']);
        $payroll = app(EmployeePayrollService::class)->create($employee, '2026-08-01', '3000', [], $this->accountant);
        $payroll = app(EmployeePayrollService::class)->approve($payroll, $this->accountant);
        app(EmployeePayrollService::class)->pay($payroll, $operating, 'cash', $this->accountant);

        $session->refresh();
        $this->assertSame('3000.00', $session->cashOut());
        $this->assertSame('7000.00', $session->expectedClosing());
    }

    /*
    |--------------------------------------------------------------------------
    | 18. Bank/InstaPay never becomes physical drawer cash
    |--------------------------------------------------------------------------
    */

    public function test_bank_and_instapay_accounts_are_not_cash_drawers_and_need_no_open_session(): void
    {
        $bank = $this->bankAccount();
        $instapay = $this->instapayAccount();
        $bank->forceFill(['balance' => '1000.00'])->save();

        $this->assertFalse($bank->isCashDrawer());
        $this->assertFalse($instapay->isCashDrawer());

        // A transfer landing on InstaPay (e.g. bank -> instapay reconciliation)
        // requires no cash session on either side, and never touches the
        // operating cash-drawer balance.
        $operating = $this->operatingAccount();
        $before = $operating->balance;

        $transfer = $this->transfers->transfer($bank->id, $instapay->id, '500.00', 'Сверка', null, $this->accountant);

        $this->assertSame('500.00', $instapay->fresh()->balance);
        $this->assertSame($before, $operating->fresh()->balance);
        $this->assertNotNull($transfer->id);
    }

    /*
    |--------------------------------------------------------------------------
    | 19. Dashboard routes / navigation
    |--------------------------------------------------------------------------
    */

    public function test_cash_operations_index_route_returns_200_and_shows_all_four_groups(): void
    {
        $this->actingAs($this->accountant)->get(route('dashboard.cash.operations.index'))
            ->assertOk()
            ->assertSee('Операционная касса')
            ->assertSee('Касса владельца')
            ->assertSee('Банковский счёт')
            ->assertSee('InstaPay');
    }

    public function test_sidebar_shows_the_cash_operations_link_for_the_accountant(): void
    {
        $this->actingAs($this->accountant)->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee(route('dashboard.cash.operations.index'), false);
    }

    public function test_handover_and_owner_return_forms_render(): void
    {
        $this->actingAs($this->accountant)->get(route('dashboard.cash.operations.handover.create'))->assertOk();
        $this->actingAs($this->accountant)->get(route('dashboard.cash.operations.owner-return.create'))->assertOk();
    }

    public function test_handover_form_submission_redirects_to_the_operations_index(): void
    {
        $operating = $this->operatingAccount();
        $owner = $this->ownerAccount();
        $operating->forceFill(['balance' => '40000.00'])->save();
        $this->sessions->open($operating, $this->accountant);

        $this->actingAs($this->accountant)->post(route('dashboard.cash.operations.handover.store'), [
            'from_account_id' => $operating->id,
            'to_account_id' => $owner->id,
            'amount' => '35000.00',
        ])->assertRedirect(route('dashboard.cash.operations.index'));

        $this->assertSame('5000.00', $operating->fresh()->balance);
        $this->assertSame(CashTransfer::TYPE_HANDOVER, CashTransfer::sole()->transfer_type);
    }

    /*
    |--------------------------------------------------------------------------
    | 20. Authorization
    |--------------------------------------------------------------------------
    */

    public function test_a_guest_is_redirected_to_login_on_every_operations_route(): void
    {
        $this->get(route('dashboard.cash.operations.index'))->assertRedirect(route('login'));
        $this->get(route('dashboard.cash.operations.handover.create'))->assertRedirect(route('login'));
        $this->post(route('dashboard.cash.operations.handover.store'), [])->assertRedirect(route('login'));
    }

    public function test_a_cashier_without_transfer_cash_cannot_use_the_handover_workflow(): void
    {
        // Cashier deliberately has no 'manage cash'/'transfer cash' — only
        // runs their own drawer shift (separation of duties, matching the
        // existing accountant/cashier split in RolesAndPermissionsSeeder).
        $cashier = User::factory()->create(['is_active' => true]);
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)->get(route('dashboard.cash.operations.handover.create'))->assertForbidden();
        $this->actingAs($cashier)->post(route('dashboard.cash.operations.handover.store'), [])->assertForbidden();
    }

    public function test_view_cash_reports_alone_can_see_the_index_but_not_perform_a_handover(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole('reception');
        $viewer->givePermissionTo('view cash reports');

        $this->actingAs($viewer)->get(route('dashboard.cash.operations.index'))->assertOk();
        $this->actingAs($viewer)->get(route('dashboard.cash.operations.handover.create'))->assertForbidden();
    }
}
