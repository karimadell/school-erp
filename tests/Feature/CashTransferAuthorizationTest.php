<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\CashTransfer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Finance Batch 4 / CashTransferController authorization
 * (docs: Finance readiness inspection): every action on this controller
 * — create(), index(), store() — previously had zero authorization at
 * all (no __construct(), no middleware), despite handling real money
 * movement between cash accounts. Gated identically to
 * CashAccountController's own write actions (permission:manage cash) —
 * this module has no separate read-only cash permission to fall back
 * to, so every action requires it, matching the objective that
 * unauthorized users must not be able to view transfer pages, submit
 * transfers, or cause any account-balance change.
 */
class CashTransferAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Portal-eligible but unprivileged ('reception', active): clears
     * EnsureAdministrativePortalAccess but lacks 'manage cash', so the
     * negative tests exercise the real 403 gate, not a portal redirect.
     */
    protected function portalUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('reception');

        return $user;
    }

    protected function cashManager(): User
    {
        $user = $this->portalUser();
        Permission::firstOrCreate(['name' => 'manage cash']);
        $user->givePermissionTo('manage cash');

        return $user;
    }

    protected function makeCashAccount(float $balance = 0): CashAccount
    {
        return CashAccount::create(['name' => 'Account ' . uniqid(), 'type' => CashAccount::TYPE_CASH, 'balance' => $balance]);
    }

    public function test_a_guest_is_redirected_to_login_on_every_transfer_route(): void
    {
        $this->get(route('dashboard.cash.transfer.form'))->assertRedirect(route('login'));
        $this->get(route('dashboard.cash.transfers'))->assertRedirect(route('login'));
        $this->post(route('dashboard.cash.transfer.store'), [])->assertRedirect(route('login'));
    }

    public function test_an_authenticated_user_without_manage_cash_is_forbidden_on_every_transfer_route(): void
    {
        $user = $this->portalUser();

        $this->actingAs($user)->get(route('dashboard.cash.transfer.form'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.cash.transfers'))->assertForbidden();
        $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [])->assertForbidden();
    }

    public function test_an_authorized_user_can_open_the_transfer_form(): void
    {
        $user = $this->cashManager();

        $this->actingAs($user)->get(route('dashboard.cash.transfer.form'))->assertOk();
    }

    public function test_an_authorized_user_can_open_the_transfer_index(): void
    {
        $user = $this->cashManager();

        $this->actingAs($user)->get(route('dashboard.cash.transfers'))->assertOk();
    }

    public function test_an_authorized_user_can_submit_a_transfer_successfully(): void
    {
        $user = $this->cashManager();
        $from = $this->makeCashAccount(balance: 500);
        $to = $this->makeCashAccount(balance: 0);

        $response = $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100,
            'purpose' => 'Test transfer',
        ]);

        $response->assertRedirect(route('dashboard.cash.transfers'));
        $response->assertSessionHasNoErrors();
        $this->assertSame(1, CashTransfer::count());
        $this->assertSame('400.00', $from->fresh()->balance);
        $this->assertSame('100.00', $to->fresh()->balance);
    }

    public function test_an_unauthorized_submission_creates_no_transfer_and_changes_no_balance(): void
    {
        $user = $this->portalUser();
        $from = $this->makeCashAccount(balance: 500);
        $to = $this->makeCashAccount(balance: 0);

        $response = $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100,
            'purpose' => 'Blocked transfer',
        ]);

        $response->assertForbidden();

        $this->assertSame(0, CashTransfer::count());
        $this->assertSame(0, CashTransaction::count());
        $this->assertSame('500.00', $from->fresh()->balance);
        $this->assertSame('0.00', $to->fresh()->balance);
    }
}
