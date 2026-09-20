<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Models\RevenueCategory;
use App\Models\RevenueEntry;
use App\Models\User;
use App\Services\Finance\InvoiceCalculationService;
use App\Services\Finance\RevenueService;
use Database\Seeders\RevenueCategorySeeder;

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
}
