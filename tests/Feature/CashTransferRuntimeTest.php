<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashTransfer;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Finance Batch 2 / CashTransferController runtime-error fixes:
 *
 * 1. create()/index() referenced Blade views ('cash.transfers.create',
 *    'cash.transfers.index') that never existed anywhere in the
 *    codebase — every visit to the sidebar-linked "Cash Transfer" page
 *    (dashboard.cash.transfer.form) fatal-errored with a
 *    ViewNotFoundException, and a successful transfer's own redirect
 *    target (dashboard.cash.transfers) did too. Fixed by pointing
 *    create() at the already-existing, already-correct
 *    dashboard.cash.transfer.create view, and adding the smallest
 *    functional dashboard.cash.transfer.index view for the list.
 * 2. index() eager-loads a 'creator' relation that did not exist on the
 *    CashTransfer model at all (only fromAccount()/toAccount() did) —
 *    a second, independent cause of the same route's 500, fixed by
 *    adding CashTransfer::creator() (belongsTo User via created_by,
 *    an already-existing, already-nullable column — no schema change).
 * 3. store()'s notes-concatenation line accessed $data['notes'] with no
 *    fallback; $data (from $request->validate()) omits the key
 *    entirely when the optional 'notes' field is absent from the
 *    request, throwing "Undefined array key notes". Fixed with a
 *    ?? null fallback — the resulting stored value is unchanged from
 *    the original intent (purpose alone, no ' - notes' suffix).
 */
class CashTransferRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCashAccount(float $balance = 0): CashAccount
    {
        return CashAccount::create(['name' => 'Account ' . uniqid(), 'type' => CashAccount::TYPE_CASH, 'balance' => $balance]);
    }

    protected function cashManager(): User
    {
        // Active + administrative role clears EnsureAdministrativePortalAccess;
        // 'manage cash' authorizes the transfer controller itself.
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('reception');
        Permission::firstOrCreate(['name' => 'manage cash']);
        $user->givePermissionTo('manage cash');

        return $user;
    }

    /**
     * Cash Operations Phase 1: any cash-drawer leg of a transfer now
     * requires an open shift, exactly like InvoicePaymentService and
     * EmployeePayrollService already require for other cash movements.
     */
    protected function openCashSession(CashAccount $account, User $actor): void
    {
        app(CashSessionService::class)->open($account, $actor);
    }

    public function test_the_transfer_form_route_returns_200(): void
    {
        $user = $this->cashManager();

        $response = $this->actingAs($user)->get(route('dashboard.cash.transfer.form'));

        $response->assertOk();
    }

    public function test_the_transfer_index_route_returns_200(): void
    {
        $user = $this->cashManager();

        $response = $this->actingAs($user)->get(route('dashboard.cash.transfers'));

        $response->assertOk();
    }

    public function test_transfer_submission_succeeds_when_notes_is_entirely_omitted_from_the_request(): void
    {
        $user = $this->cashManager();
        $from = $this->makeCashAccount(balance: 500);
        $to = $this->makeCashAccount(balance: 0);
        $this->openCashSession($from, $user);
        $this->openCashSession($to, $user);

        // Deliberately no 'notes' key at all in the payload — this is the
        // exact condition that used to throw "Undefined array key notes".
        $response = $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100,
            'purpose' => 'Test transfer',
        ]);

        $response->assertRedirect(route('dashboard.cash.transfers'));
        $response->assertSessionHasNoErrors();
    }

    public function test_the_transfer_is_persisted_with_the_intended_notes_value_when_notes_is_omitted(): void
    {
        $user = $this->cashManager();
        $from = $this->makeCashAccount(balance: 500);
        $to = $this->makeCashAccount(balance: 0);
        $this->openCashSession($from, $user);
        $this->openCashSession($to, $user);

        $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100,
            'purpose' => 'Test transfer',
        ]);

        $transfer = CashTransfer::sole();

        // No 'notes' submitted — the original " - {notes}" suffix logic
        // never applied, so the stored value is exactly the purpose text,
        // matching what the pre-crash code already intended for a blank
        // notes field.
        $this->assertSame('Test transfer', $transfer->notes);
    }

    public function test_end_to_end_a_submitted_transfer_is_visible_on_the_index_page_after_the_redirect(): void
    {
        $user = $this->cashManager();
        $from = $this->makeCashAccount(balance: 500);
        $to = $this->makeCashAccount(balance: 0);
        $this->openCashSession($from, $user);
        $this->openCashSession($to, $user);

        $storeResponse = $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100,
            'purpose' => 'Test transfer',
        ]);

        $storeResponse->assertRedirect(route('dashboard.cash.transfers'));

        $indexResponse = $this->actingAs($user)->get(route('dashboard.cash.transfers'));

        $indexResponse->assertOk();
        $indexResponse->assertSee($from->name);
        $indexResponse->assertSee($to->name);

        $transfer = CashTransfer::sole();
        $indexResponse->assertSee($transfer->receipt_number);
    }

    /*
    |--------------------------------------------------------------------------
    | Finance Batch 3 / Transfer form <-> validation mismatch
    |--------------------------------------------------------------------------
    | store()'s validation has always required 'purpose', but the rendered
    | create form never had a 'purpose' input at all — so a real user could
    | never submit the actual page successfully. The translation key
    | (cash.purpose) already existed; only the field itself was missing.
    */

    public function test_the_transfer_form_renders_the_purpose_field_with_its_russian_label(): void
    {
        $user = $this->cashManager();

        app()->setLocale('ru');

        $response = $this->actingAs($user)->get(route('dashboard.cash.transfer.form'));

        $response->assertOk();
        $response->assertSee('name="purpose"', false);
        $response->assertSee(__('cash.purpose'));
    }

    public function test_a_browser_style_submission_using_only_the_fields_present_in_the_form_succeeds(): void
    {
        $user = $this->cashManager();
        $from = $this->makeCashAccount(balance: 500);
        $to = $this->makeCashAccount(balance: 0);
        $this->openCashSession($from, $user);
        $this->openCashSession($to, $user);

        // Exactly the fields the rendered form actually provides:
        // from_account_id, to_account_id, amount, transfer_date, purpose,
        // notes — nothing else.
        $response = $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 250,
            'transfer_date' => now()->toDateString(),
            'purpose' => 'Office supplies',
            'notes' => 'Optional detail',
        ]);

        $response->assertRedirect(route('dashboard.cash.transfers'));
        $response->assertSessionHasNoErrors();

        $this->assertSame(1, CashTransfer::count());
        $this->assertSame('250.00', $from->fresh()->balance);
        $this->assertSame('250.00', $to->fresh()->balance);
    }

    public function test_omitting_purpose_redirects_back_with_a_validation_error_and_creates_nothing(): void
    {
        $user = $this->cashManager();
        $from = $this->makeCashAccount(balance: 500);
        $to = $this->makeCashAccount(balance: 0);

        // Deliberately no 'purpose' key at all — proves the still-required
        // rule is enforced normally (a validation redirect), not a runtime
        // exception, and that nothing is written when it fails.
        $response = $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100,
        ]);

        $response->assertSessionHasErrors('purpose');

        $this->assertSame(0, CashTransfer::count());
        $this->assertSame('500.00', $from->fresh()->balance);
        $this->assertSame('0.00', $to->fresh()->balance);
    }
}
