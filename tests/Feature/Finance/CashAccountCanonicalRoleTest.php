<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashSession;
use App\Models\CashTransaction;
use App\Models\User;
use App\Services\Finance\CashAccountRoleService;
use App\Services\Finance\CashSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Root cause under test: the original Phase 1 seeding migration
 * identified "the operating account" by exact display name, so UAT's
 * pre-existing, differently-named drawer ("UAT — Основная касса") wasn't
 * recognised and a duplicate ("Операционная касса") got created instead.
 *
 * CashAccountRoleService is the fix: it resolves/assigns the four
 * canonical roles (operating/owner/bank/instapay) by adopting an
 * existing, unambiguous account of the matching type — never by name,
 * never by guessing when more than one candidate exists.
 */
class CashAccountCanonicalRoleTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | 1. Fresh DB
    |--------------------------------------------------------------------------
    */

    public function test_fresh_database_receives_exactly_one_canonical_operating_role(): void
    {
        // RefreshDatabase already ran every migration (including the
        // Phase 1 seed and the role backfill) before this test body runs.
        $this->assertSame(1, CashAccount::where('role', CashAccount::ROLE_OPERATING)->count());
        $this->assertNotNull(CashAccount::operating());
    }

    /*
    |--------------------------------------------------------------------------
    | 2-5. Adopting a differently-named existing operating drawer
    |--------------------------------------------------------------------------
    */

    public function test_existing_differently_named_single_cash_drawer_is_adopted_as_operating(): void
    {
        // Simulate the exact UAT shape: the Phase 1 seed's own
        // "Операционная касса" is not present (as if never created, or
        // already cleaned up), and a differently-named historical drawer
        // with real transaction history is the only candidate.
        CashAccount::where('type', CashAccount::TYPE_CASH)->delete();
        $historical = CashAccount::create([
            'name' => 'UAT — Основная касса', 'type' => CashAccount::TYPE_CASH,
            'balance' => '-14700.00', 'is_active' => true,
        ]);
        CashTransaction::insert([
            ['cash_account_id' => $historical->id, 'type' => 'in', 'amount' => '5000.00', 'created_at' => now(), 'updated_at' => now()],
            ['cash_account_id' => $historical->id, 'type' => 'out', 'amount' => '19700.00', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = app(CashAccountRoleService::class)->ensureRole(CashAccount::ROLE_OPERATING, CashAccount::TYPE_CASH, 'Операционная касса');

        $this->assertSame(CashAccountRoleService::ADOPTED, $result);
        $historical->refresh();

        // 3. Name unchanged.
        $this->assertSame('UAT — Основная касса', $historical->name);
        // 4. Balance unchanged.
        $this->assertSame('-14700.00', $historical->balance);
        // 5. Transactions remain attached.
        $this->assertSame(2, CashTransaction::where('cash_account_id', $historical->id)->count());
        // Adopted, not duplicated.
        $this->assertSame(1, CashAccount::where('type', CashAccount::TYPE_CASH)->count());
        $this->assertSame($historical->id, CashAccount::operating()->id);
    }

    /*
    |--------------------------------------------------------------------------
    | 6-8. owner_cash / bank / instapay adopted, not duplicated
    |--------------------------------------------------------------------------
    */

    public function test_existing_owner_cash_account_is_adopted_not_duplicated(): void
    {
        CashAccount::where('type', CashAccount::TYPE_OWNER_CASH)->delete();
        $existing = CashAccount::create(['name' => 'Личная касса директора', 'type' => CashAccount::TYPE_OWNER_CASH, 'balance' => '500.00', 'is_active' => true]);

        $result = app(CashAccountRoleService::class)->ensureRole(CashAccount::ROLE_OWNER, CashAccount::TYPE_OWNER_CASH, 'Касса владельца');

        $this->assertSame(CashAccountRoleService::ADOPTED, $result);
        $this->assertSame(1, CashAccount::where('type', CashAccount::TYPE_OWNER_CASH)->count());
        $this->assertSame($existing->id, CashAccount::owner()->id);
        $this->assertSame('Личная касса директора', $existing->fresh()->name);
    }

    public function test_existing_bank_account_is_adopted_not_duplicated(): void
    {
        CashAccount::where('type', CashAccount::TYPE_BANK)->delete();
        $existing = CashAccount::create(['name' => 'CIB текущий счёт', 'type' => CashAccount::TYPE_BANK, 'balance' => '10000.00', 'is_active' => true]);

        $result = app(CashAccountRoleService::class)->ensureRole(CashAccount::ROLE_BANK, CashAccount::TYPE_BANK, 'Банковский счёт');

        $this->assertSame(CashAccountRoleService::ADOPTED, $result);
        $this->assertSame(1, CashAccount::where('type', CashAccount::TYPE_BANK)->count());
        $this->assertSame($existing->id, CashAccount::bank()->id);
    }

    public function test_existing_instapay_account_is_adopted_not_duplicated(): void
    {
        CashAccount::where('type', CashAccount::TYPE_INSTAPAY)->delete();
        $existing = CashAccount::create(['name' => 'InstaPay (старый)', 'type' => CashAccount::TYPE_INSTAPAY, 'balance' => '0.00', 'is_active' => true]);

        $result = app(CashAccountRoleService::class)->ensureRole(CashAccount::ROLE_INSTAPAY, CashAccount::TYPE_INSTAPAY, 'InstaPay');

        $this->assertSame(CashAccountRoleService::ADOPTED, $result);
        $this->assertSame(1, CashAccount::where('type', CashAccount::TYPE_INSTAPAY)->count());
        $this->assertSame($existing->id, CashAccount::instapay()->id);
    }

    /*
    |--------------------------------------------------------------------------
    | 9. Re-running is safe (idempotent)
    |--------------------------------------------------------------------------
    */

    public function test_rerunning_role_resolution_does_not_create_duplicates(): void
    {
        $service = app(CashAccountRoleService::class);
        $before = CashAccount::count();

        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $service->ensureRole(CashAccount::ROLE_OPERATING, CashAccount::TYPE_CASH, 'Операционная касса');
        }

        $this->assertSame(CashAccount::count(), $before);
        $this->assertSame(1, CashAccount::where('role', CashAccount::ROLE_OPERATING)->count());
        // First call (if role was already set by the migration) or the
        // very first of these three is a no-op either way; subsequent
        // calls are always ALREADY_ASSIGNED.
        $this->assertSame(CashAccountRoleService::ALREADY_ASSIGNED, $results[1]);
        $this->assertSame(CashAccountRoleService::ALREADY_ASSIGNED, $results[2]);
    }

    public function test_fresh_install_with_zero_candidates_creates_the_canonical_account(): void
    {
        CashAccount::where('type', CashAccount::TYPE_INSTAPAY)->delete();

        $result = app(CashAccountRoleService::class)->ensureRole(CashAccount::ROLE_INSTAPAY, CashAccount::TYPE_INSTAPAY, 'InstaPay');

        $this->assertSame(CashAccountRoleService::CREATED, $result);
        $created = CashAccount::instapay();
        $this->assertNotNull($created);
        $this->assertSame('InstaPay', $created->name);
        $this->assertSame('0.00', $created->balance);
    }

    /*
    |--------------------------------------------------------------------------
    | 10-11. Lookup by role, not name — survives a rename
    |--------------------------------------------------------------------------
    */

    public function test_canonical_lookup_works_by_role_not_name(): void
    {
        $operating = CashAccount::operating();
        $this->assertNotNull($operating);

        // Renaming must not break the lookup — role is the identity, the
        // name is just a label.
        $operating->update(['name' => 'Совершенно другое имя']);

        $this->assertSame($operating->id, CashAccount::operating()->fresh()->id);
        $this->assertSame('Совершенно другое имя', CashAccount::operating()->name);
    }

    /*
    |--------------------------------------------------------------------------
    | 12. Duplicate canonical roles are rejected at the DB level
    |--------------------------------------------------------------------------
    */

    public function test_a_second_account_cannot_take_an_already_assigned_role(): void
    {
        $this->assertNotNull(CashAccount::operating());

        $this->expectException(\Illuminate\Database\QueryException::class);
        CashAccount::create([
            'name' => 'Вторая операционная касса', 'type' => CashAccount::TYPE_CASH,
            'role' => CashAccount::ROLE_OPERATING, 'balance' => '0.00', 'is_active' => true,
        ]);
    }

    public function test_role_column_rejects_an_unknown_value(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        CashAccount::create([
            'name' => 'Мусорная роль', 'type' => CashAccount::TYPE_CASH,
            'role' => 'not_a_real_role', 'balance' => '0.00', 'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 13. Multiple ordinary (non-canonical) cash drawers remain supported
    |--------------------------------------------------------------------------
    */

    public function test_multiple_ordinary_cash_drawers_coexist_alongside_the_canonical_one(): void
    {
        $operating = CashAccount::operating();
        $extraDrawer1 = CashAccount::create(['name' => 'Касса — филиал 2', 'type' => CashAccount::TYPE_CASH, 'balance' => '0.00', 'is_active' => true]);
        $extraDrawer2 = CashAccount::create(['name' => 'Касса — филиал 3', 'type' => CashAccount::TYPE_CASH, 'balance' => '0.00', 'is_active' => true]);

        $this->assertNull($extraDrawer1->role);
        $this->assertNull($extraDrawer2->role);
        $this->assertSame($operating->id, CashAccount::operating()->id);
        $this->assertSame(3, CashAccount::where('type', CashAccount::TYPE_CASH)->count());

        // Phase 3 cash sessions must still work on an ordinary, non-canonical drawer.
        $user = User::factory()->create(['is_active' => true]);
        $session = app(CashSessionService::class)->open($extraDrawer1, $user);
        $this->assertInstanceOf(CashSession::class, $session);
    }

    /*
    |--------------------------------------------------------------------------
    | 14. Ambiguous multiple historical drawers are never guessed
    |--------------------------------------------------------------------------
    */

    public function test_ambiguous_multiple_unroled_drawers_are_not_auto_assigned(): void
    {
        CashAccount::where('type', CashAccount::TYPE_CASH)->delete();
        $first = CashAccount::create(['name' => 'Касса А', 'type' => CashAccount::TYPE_CASH, 'balance' => '1000.00', 'is_active' => true]);
        $second = CashAccount::create(['name' => 'Касса Б', 'type' => CashAccount::TYPE_CASH, 'balance' => '2000.00', 'is_active' => true]);

        $result = app(CashAccountRoleService::class)->ensureRole(CashAccount::ROLE_OPERATING, CashAccount::TYPE_CASH, 'Операционная касса');

        $this->assertSame(CashAccountRoleService::AMBIGUOUS, $result);
        $this->assertNull(CashAccount::operating());
        $this->assertNull($first->fresh()->role);
        $this->assertNull($second->fresh()->role);
        // Balances/names untouched either way.
        $this->assertSame('1000.00', $first->fresh()->balance);
        $this->assertSame('2000.00', $second->fresh()->balance);
        $this->assertSame(2, CashAccount::where('type', CashAccount::TYPE_CASH)->count());
    }

    public function test_an_inactive_account_is_not_counted_as_a_role_candidate(): void
    {
        CashAccount::where('type', CashAccount::TYPE_CASH)->delete();
        CashAccount::create(['name' => 'Закрытая касса', 'type' => CashAccount::TYPE_CASH, 'balance' => '0.00', 'is_active' => false]);

        $result = app(CashAccountRoleService::class)->ensureRole(CashAccount::ROLE_OPERATING, CashAccount::TYPE_CASH, 'Операционная касса');

        // Zero *active* candidates -> a fresh canonical account is
        // created; the inactive one is left exactly as it was.
        $this->assertSame(CashAccountRoleService::CREATED, $result);
        $this->assertSame(2, CashAccount::where('type', CashAccount::TYPE_CASH)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | 15-16. Phase 1 / Cash Session regressions remain green (smoke checks —
    | the full suites are run separately, see the task report)
    |--------------------------------------------------------------------------
    */

    public function test_cash_operations_index_still_resolves_all_four_roles_after_backfill(): void
    {
        $this->actingAs($this->accountant())->get(route('dashboard.cash.operations.index'))->assertOk();
    }

    public function test_a_cash_session_can_still_be_opened_on_the_canonical_operating_account(): void
    {
        $operating = CashAccount::operating();
        $user = User::factory()->create(['is_active' => true]);

        $session = app(CashSessionService::class)->open($operating, $user);

        $this->assertSame($operating->id, $session->cash_account_id);
    }

    private function accountant(): User
    {
        (new \Database\Seeders\RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('accountant');

        return $user;
    }
}
