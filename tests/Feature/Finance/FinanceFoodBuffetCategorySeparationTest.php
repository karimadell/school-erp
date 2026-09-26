<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Models\RevenueCategory;
use App\Models\RevenueEntry;
use App\Models\User;
use App\Services\Finance\InvoiceCalculationService;
use App\Services\Finance\RevenueService;
use Database\Seeders\RevenueCategorySeeder;
use Illuminate\Support\Facades\DB;

/**
 * Pre-go-live Finance category separation (owner-approved): School Food
 * ("school_food"/"Школьное питание") and Buffet ("buffet"/"Буфет") are two
 * new, distinct RevenueCategory rows — the legacy "cafeteria"/"Кафетерий"
 * row is left completely untouched, never renamed/repurposed. Only the
 * Buffet daily-handover Dashboard shortcut is built in this PR; Staff Food
 * purchases and payroll deductions are explicitly out of scope. Student
 * Food continues through Invoice/Payment/CashTransaction and must never
 * create a RevenueEntry.
 */
class FinanceFoodBuffetCategorySeparationTest extends FinanceOperationsTestCase
{
    // 1, 2, 3. Both new categories are seeded exactly once, idempotently.
    // A. school_food seeded ACTIVE — Stolovaya Phase 2 (Employee cash
    // purchases) shipped, so the "reserved until that feature exists"
    // condition from the pre-go-live seeder comment is now satisfied
    // (previously seeded INACTIVE; see the Phase 2 activation migration
    // 2026_09_26_120100_activate_school_food_revenue_category for how an
    // already-provisioned/UAT database gets flipped). B. buffet seeded
    // ACTIVE (its shortcut has been live since before this).
    public function test_school_food_and_buffet_categories_are_seeded_idempotently(): void
    {
        (new RevenueCategorySeeder)->run();
        (new RevenueCategorySeeder)->run();

        $schoolFood = RevenueCategory::where('code', RevenueCategory::CODE_SCHOOL_FOOD)->get();
        $buffet = RevenueCategory::where('code', RevenueCategory::CODE_BUFFET)->get();

        $this->assertCount(1, $schoolFood);
        $this->assertCount(1, $buffet);
        $this->assertSame('Школьное питание', $schoolFood->first()->name_ru);
        $this->assertSame('Буфет', $buffet->first()->name_ru);
        $this->assertTrue($schoolFood->first()->is_active);
        $this->assertTrue($buffet->first()->is_active);
    }

    // 4. Legacy cafeteria remains completely unchanged.
    public function test_legacy_cafeteria_category_is_unchanged(): void
    {
        $before = RevenueCategory::firstOrCreate(['code' => RevenueCategory::CODE_CAFETERIA], ['name_ru' => 'Кафетерий', 'is_active' => true]);

        (new RevenueCategorySeeder)->run();

        $after = RevenueCategory::where('code', RevenueCategory::CODE_CAFETERIA)->sole();
        $this->assertSame($before->id, $after->id);
        $this->assertSame('Кафетерий', $after->name_ru);
        $this->assertTrue($after->is_active);
    }

    // 5. Every other existing category remains unchanged.
    public function test_existing_categories_remain_unchanged(): void
    {
        (new RevenueCategorySeeder)->run();

        $this->assertSame('Пожертвования', RevenueCategory::where('code', RevenueCategory::CODE_DONATION)->value('name_ru'));
        $this->assertSame('Штрафы', RevenueCategory::where('code', RevenueCategory::CODE_FINE)->value('name_ru'));
        $this->assertSame('Прочие доходы', RevenueCategory::where('code', RevenueCategory::CODE_OTHER)->value('name_ru'));
    }

    // D (Stolovaya Phase 2 corrective — supersedes the earlier "offers
    // active school_food category" version of this test). Owner decision:
    // school_food is a CONTROLLED revenue category — even though it is
    // now active, it must NEVER appear in the generic (unlocked) "Прочий
    // приход" form's category choices. Every NEW operational school_food
    // RevenueEntry must originate through the dedicated Employee
    // Stolovaya workflow (StaffFoodPurchase -> RevenueService::
    // createTrusted()), never through this free-choice selector. Every
    // other active category (donation, fine, other, and — per its own
    // separate locked shortcut — buffet) remains offered exactly as
    // before; only school_food's stable code is excluded (see
    // RevenueEntryController::formOptions()).
    public function test_generic_revenue_form_never_offers_school_food_category(): void
    {
        (new RevenueCategorySeeder)->run();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.revenue.create'));

        $response->assertOk();
        $response->assertDontSee('Школьное питание');
        // Every other active category remains offered.
        $response->assertSee('Пожертвования');
        $response->assertSee('Штрафы');
        $response->assertSee('Прочие доходы');
    }

    // Server-side enforcement (the UI exclusion above is not sufficient on
    // its own): a crafted POST to the generic RevenueEntry store endpoint
    // carrying school_food's id — with NO locked "type" discriminator, so
    // applyLockedCategory() never touches it — must be rejected before
    // RevenueService::create() is ever called, creating zero RevenueEntry
    // and zero CashTransaction. Never silently remapped to another
    // category — a real, visible validation error.
    public function test_crafted_generic_revenue_post_with_school_food_category_is_rejected(): void
    {
        (new RevenueCategorySeeder)->run();
        $schoolFood = RevenueCategory::where('code', RevenueCategory::CODE_SCHOOL_FOOD)->sole();
        $entriesBefore = RevenueEntry::count();
        $transactionsBefore = CashTransaction::count();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.finance.income.revenue.store'), [
            'revenue_category_id' => $schoolFood->id,
            'amount' => '150.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
            'status' => RevenueEntry::STATUS_POSTED,
        ]);

        $response->assertSessionHasErrors('revenue_category_id');
        $this->assertSame($entriesBefore, RevenueEntry::count(), 'No RevenueEntry was created.');
        $this->assertSame($transactionsBefore, CashTransaction::count(), 'No CashTransaction was created.');
    }

    // 6. The Buffet shortcut resolves to, and locks, only the buffet category.
    public function test_buffet_shortcut_resolves_only_buffet_category(): void
    {
        (new RevenueCategorySeeder)->run();
        $buffet = RevenueCategory::where('code', RevenueCategory::CODE_BUFFET)->sole();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.buffet'));
        $response->assertRedirect(route('dashboard.finance.income.revenue.create', ['type' => 'buffet']));

        $createResponse = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.revenue.create', ['type' => 'buffet']));
        $createResponse->assertOk();
        $createResponse->assertSee('Буфет');
        $createResponse->assertSee('name="revenue_category_id" value="'.$buffet->id.'"', false);
    }

    // 7 & 8. Posting a Buffet RevenueEntry creates exactly one
    // CashTransaction with the correct amount/date/cash account.
    public function test_posted_buffet_revenue_entry_creates_exactly_one_correct_cash_transaction(): void
    {
        (new RevenueCategorySeeder)->run();
        $buffet = RevenueCategory::where('code', RevenueCategory::CODE_BUFFET)->sole();

        $entry = app(RevenueService::class)->create([
            'revenue_category_id' => $buffet->id,
            'amount' => '2000.00',
            'revenue_date' => '2026-09-19',
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
            'status' => RevenueEntry::STATUS_POSTED,
        ], $this->accountant);

        $this->assertTrue($entry->isPosted());
        $this->assertSame('2026-09-19', $entry->fresh()->revenue_date->toDateString());
        $transactions = CashTransaction::query()->where('revenue_entry_id', $entry->id)->get();
        $this->assertCount(1, $transactions);
        $transaction = $transactions->first();
        $this->assertSame('2000.00', (string) $transaction->amount);
        $this->assertSame($this->cash->id, $transaction->cash_account_id);
    }

    private function buffetPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'buffet',
            'amount' => '2000.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
            'status' => RevenueEntry::STATUS_DRAFT,
        ], $overrides);
    }

    // F. A crafted Buffet POST carrying the legacy cafeteria category id
    // still persists RevenueCategory::CODE_BUFFET — the server-side lock
    // ignores the tampered field, resolving strictly from type=buffet.
    public function test_crafted_buffet_post_with_cafeteria_id_still_persists_buffet(): void
    {
        (new RevenueCategorySeeder)->run();
        $buffet = RevenueCategory::where('code', RevenueCategory::CODE_BUFFET)->sole();
        $cafeteria = RevenueCategory::firstOrCreate(['code' => RevenueCategory::CODE_CAFETERIA], ['name_ru' => 'Кафетерий', 'is_active' => true]);
        $before = RevenueEntry::count();

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.finance.income.revenue.store'),
            $this->buffetPayload(['revenue_category_id' => $cafeteria->id]),
        );

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame($before + 1, RevenueEntry::count());
        $entry = RevenueEntry::latest('id')->first();
        $this->assertSame($buffet->id, $entry->revenue_category_id);
        $this->assertNotSame($cafeteria->id, $entry->revenue_category_id);
    }

    // G. A crafted Buffet POST carrying the school_food category id also
    // still persists Buffet, never school_food — unaffected by whether
    // school_food is active or not (Stolovaya Phase 2 activated it; the
    // server-side category lock this test proves was never conditioned
    // on that in the first place).
    public function test_crafted_buffet_post_with_school_food_id_still_persists_buffet(): void
    {
        (new RevenueCategorySeeder)->run();
        $buffet = RevenueCategory::where('code', RevenueCategory::CODE_BUFFET)->sole();
        $schoolFood = RevenueCategory::where('code', RevenueCategory::CODE_SCHOOL_FOOD)->sole();
        $before = RevenueEntry::count();

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.finance.income.revenue.store'),
            $this->buffetPayload(['revenue_category_id' => $schoolFood->id]),
        );

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame($before + 1, RevenueEntry::count());
        $entry = RevenueEntry::latest('id')->first();
        $this->assertSame($buffet->id, $entry->revenue_category_id);
        $this->assertNotSame($schoolFood->id, $entry->revenue_category_id);
    }

    // H. The same shared mechanism covers Donation — a crafted Donation
    // POST carrying another category id still persists Donation.
    public function test_crafted_donation_post_with_another_category_id_still_persists_donation(): void
    {
        (new RevenueCategorySeeder)->run();
        $donation = RevenueCategory::where('code', RevenueCategory::CODE_DONATION)->sole();
        $fine = RevenueCategory::firstOrCreate(['code' => RevenueCategory::CODE_FINE], ['name_ru' => 'Штрафы', 'is_active' => true]);
        $before = RevenueEntry::count();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.finance.income.revenue.store'), [
            'type' => 'donation',
            'revenue_category_id' => $fine->id,
            'amount' => '500.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
            'status' => RevenueEntry::STATUS_DRAFT,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame($before + 1, RevenueEntry::count());
        $entry = RevenueEntry::latest('id')->first();
        $this->assertSame($donation->id, $entry->revenue_category_id);
    }

    // I. The generic "Прочий приход" (Other) workflow is untouched by the
    // lock mechanism — a legitimate, freely-selected active category
    // still persists exactly as chosen.
    public function test_generic_other_workflow_still_accepts_legitimate_selected_category(): void
    {
        (new RevenueCategorySeeder)->run();
        $fine = RevenueCategory::firstOrCreate(['code' => RevenueCategory::CODE_FINE], ['name_ru' => 'Штрафы', 'is_active' => true]);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.finance.income.revenue.store'), [
            'revenue_category_id' => $fine->id,
            'amount' => '300.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
            'status' => RevenueEntry::STATUS_DRAFT,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $entry = RevenueEntry::latest('id')->first();
        $this->assertSame($fine->id, $entry->revenue_category_id);
    }

    // 9 & 10. A Buffet entry is never classified as school_food or cafeteria.
    public function test_buffet_entry_is_not_classified_as_school_food_or_cafeteria(): void
    {
        (new RevenueCategorySeeder)->run();
        $buffet = RevenueCategory::where('code', RevenueCategory::CODE_BUFFET)->sole();
        $schoolFood = RevenueCategory::where('code', RevenueCategory::CODE_SCHOOL_FOOD)->sole();
        $cafeteria = RevenueCategory::firstOrCreate(['code' => RevenueCategory::CODE_CAFETERIA], ['name_ru' => 'Кафетерий', 'is_active' => true]);

        $entry = app(RevenueService::class)->create([
            'revenue_category_id' => $buffet->id,
            'amount' => '2000.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
        ], $this->accountant);

        $this->assertNotSame($schoolFood->id, $entry->revenue_category_id);
        $this->assertNotSame($cafeteria->id, $entry->revenue_category_id);
        $this->assertSame($buffet->id, $entry->revenue_category_id);
    }

    // 11. Existing Donation workflow remains unaffected by the refactor.
    public function test_existing_donation_workflow_still_locks_donation_category(): void
    {
        (new RevenueCategorySeeder)->run();
        $donation = RevenueCategory::where('code', RevenueCategory::CODE_DONATION)->sole();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.donation'));
        $response->assertRedirect(route('dashboard.finance.income.revenue.create', ['type' => 'donation']));

        $createResponse = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.revenue.create', ['type' => 'donation']));
        $createResponse->assertOk();
        $createResponse->assertSee('Пожертвование');
        $createResponse->assertSee('name="revenue_category_id" value="'.$donation->id.'"', false);
    }

    // 12. Existing generic (unlocked) RevenueEntry workflow remains unaffected.
    public function test_generic_other_income_workflow_remains_unlocked(): void
    {
        (new RevenueCategorySeeder)->run();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.other'));
        $response->assertRedirect(route('dashboard.finance.income.revenue.create'));

        $createResponse = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.revenue.create'));
        $createResponse->assertOk();
        $createResponse->assertSee('Прочий приход');
        $createResponse->assertSee('name="revenue_category_id"', false);
        $createResponse->assertDontSee('name="revenue_category_id" value="', false);
    }

    // 13. Student Food purchases never create a RevenueEntry.
    public function test_student_food_flow_does_not_create_a_revenue_entry(): void
    {
        $before = RevenueEntry::count();

        $calculation = app(InvoiceCalculationService::class)->calculate(
            items: [['fee_id' => $this->fee->id, 'grade_id' => $this->enrollment->grade_id, 'quantity' => 1]],
            pricingDate: '2026-09-10',
            academicYearId: $this->year->id,
        );

        $this->assertNotEmpty($calculation['line_items']);
        $this->assertSame($before, RevenueEntry::count());
    }

    // 14. Authorization remains correct — a user without 'manage revenues'
    // cannot reach the Buffet create form (the redirect shortcut itself
    // only shares the Приход-wide 'view invoices' gate, exactly like
    // donation()/other() already did; the real boundary is
    // RevenueEntryPolicy::create(), unchanged by this PR).
    public function test_unauthorized_user_cannot_reach_buffet_create_form(): void
    {
        (new RevenueCategorySeeder)->run();
        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');

        $this->actingAs($reception)->get(route('dashboard.finance.income.buffet'))
            ->assertRedirect(route('dashboard.finance.income.revenue.create', ['type' => 'buffet']));
        $this->actingAs($reception)
            ->get(route('dashboard.finance.income.revenue.create', ['type' => 'buffet']))
            ->assertForbidden();
    }

    // Stolovaya Phase 2 corrective — high-value coverage the independent
    // review found missing: the activation migration's own up() behavior,
    // exercised directly (RefreshDatabase's normal fresh-migrate flow
    // never lets "a row already existed, inactive, before this migration
    // was ever written" occur naturally, since the migration is always
    // present from the start of a fresh test database).

    // A1. Existing inactive school_food row: activation flips it, no
    // duplicate, buffet/cafeteria untouched.
    public function test_activation_migration_flips_a_preexisting_inactive_school_food_row(): void
    {
        $buffet = DB::table('revenue_categories')->where('code', RevenueCategory::CODE_BUFFET)->first()
            ?: ['id' => DB::table('revenue_categories')->insertGetId(['code' => RevenueCategory::CODE_BUFFET, 'name_ru' => 'Буфет', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()])];
        $cafeteriaId = DB::table('revenue_categories')->where('code', RevenueCategory::CODE_CAFETERIA)->value('id')
            ?: DB::table('revenue_categories')->insertGetId(['code' => RevenueCategory::CODE_CAFETERIA, 'name_ru' => 'Кафетерий', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        DB::table('revenue_categories')->where('code', RevenueCategory::CODE_SCHOOL_FOOD)->delete();
        DB::table('revenue_categories')->insert([
            'code' => RevenueCategory::CODE_SCHOOL_FOOD, 'name_ru' => 'Школьное питание',
            'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_26_120100_activate_school_food_revenue_category.php');
        $migration->up();

        $this->assertTrue((bool) DB::table('revenue_categories')->where('code', RevenueCategory::CODE_SCHOOL_FOOD)->value('is_active'));
        $this->assertSame(1, DB::table('revenue_categories')->where('code', RevenueCategory::CODE_SCHOOL_FOOD)->count(), 'No duplicate row.');
        $this->assertTrue((bool) DB::table('revenue_categories')->where('id', is_array($buffet) ? $buffet['id'] : $buffet->id)->value('is_active'), 'Buffet unchanged (still active).');
        $this->assertTrue((bool) DB::table('revenue_categories')->where('id', $cafeteriaId)->value('is_active'), 'Cafeteria unchanged (still active).');
    }

    // A2. Fresh-install path: migration safely no-ops when the row does
    // not exist yet, and later seeding creates it active (proving the
    // full lifecycle, not just the migration in isolation).
    public function test_activation_migration_noops_on_fresh_install_then_seeding_creates_it_active(): void
    {
        DB::table('revenue_categories')->where('code', RevenueCategory::CODE_SCHOOL_FOOD)->delete();
        $this->assertSame(0, DB::table('revenue_categories')->where('code', RevenueCategory::CODE_SCHOOL_FOOD)->count());

        $migration = require database_path('migrations/2026_09_26_120100_activate_school_food_revenue_category.php');
        $migration->up();

        $this->assertSame(0, DB::table('revenue_categories')->where('code', RevenueCategory::CODE_SCHOOL_FOOD)->count(), 'Migration alone creates nothing.');

        (new RevenueCategorySeeder)->run();

        $this->assertTrue((bool) RevenueCategory::where('code', RevenueCategory::CODE_SCHOOL_FOOD)->value('is_active'), 'Seeding on a fresh install creates it already active.');
    }
}
