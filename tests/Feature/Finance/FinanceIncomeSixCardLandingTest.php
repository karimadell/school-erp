<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Models\RevenueCategory;
use App\Models\RevenueEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PR 3 of the frozen Finance corrective plan — the Приход landing has
 * exactly six operational cards (Ученики: Оплата ученика, Новый ученик /
 * Новый учебный год, Столовая; Доходы школы: Буфет, Пожертвование, Прочий
 * приход), each visible only to an actor its destination actually admits.
 * The single Столовая card chooses between the separate Student and Employee
 * engines, and the generic «Прочий приход» form can never create an entry in
 * a controlled (school_food/buffet/donation) or legacy (cafeteria) category.
 */
class FinanceIncomeSixCardLandingTest extends FinanceOperationsTestCase
{
    private const ALL_CARDS = ['payment', 'registration', 'stolovaya', 'buffet', 'donation', 'other'];

    private function landing(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->get(route('dashboard.finance.income.index'))->assertOk();
    }

    /** @return array<int, string> */
    private function visibleCards(User $user): array
    {
        preg_match_all('/data-income-card="([a-z]+)"/', $this->landing($user)->getContent(), $matches);

        return $matches[1];
    }

    private function receptionWith(string ...$permissions): User
    {
        $user = $this->user('reception');
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function category(string $code, string $name): RevenueCategory
    {
        return RevenueCategory::firstOrCreate(['code' => $code], ['name_ru' => $name, 'is_active' => true]);
    }

    private function genericPayload(RevenueCategory $category, array $overrides = []): array
    {
        return array_merge([
            'revenue_category_id' => $category->id,
            'amount' => '250.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
            'status' => RevenueEntry::STATUS_POSTED,
        ], $overrides);
    }

    // ----- A. Landing structure -------------------------------------------

    public function test_full_finance_user_sees_exactly_the_six_cards_in_two_sections(): void
    {
        $this->assertSame(self::ALL_CARDS, $this->visibleCards($this->accountant));

        $html = $this->landing($this->accountant)->getContent();
        $this->assertStringContainsString('data-income-section="students"', $html);
        $this->assertStringContainsString('data-income-section="school"', $html);
        $this->assertSame(1, substr_count($html, 'href="'.route('dashboard.finance.income.stolovaya').'"'));
    }

    public function test_no_top_level_additional_service_or_employee_stolovaya_card(): void
    {
        $response = $this->landing($this->accountant);

        $response->assertDontSee(route('dashboard.finance.income.students', ['context' => 'service']), false);
        $response->assertDontSee(__('finance_workspace.income_type_service'));
        $response->assertDontSee('href="'.route('dashboard.employee-stolovaya.create').'"', false);
        $response->assertDontSee(__('finance_workspace.stolovaya_employee_card_label'));
    }

    public function test_every_role_with_full_finance_permissions_sees_all_six_cards(): void
    {
        foreach (['super-admin', 'admin', 'school-admin', 'principal', 'accountant'] as $role) {
            $this->assertSame(self::ALL_CARDS, $this->visibleCards($this->user($role)), $role);
        }
    }

    // ----- B. Permission-correct visibility --------------------------------

    public function test_cashier_sees_only_student_cards_and_cannot_reach_generic_revenue_buffet_or_donation(): void
    {
        $cashier = $this->user('cashier');
        $this->assertTrue($cashier->can('manage invoices'));
        $this->assertTrue($cashier->can('manage employee stolovaya'));
        $this->assertFalse($cashier->can('manage revenues'));

        $this->assertSame(['payment', 'registration', 'stolovaya'], $this->visibleCards($cashier));
        $this->landing($cashier)->assertDontSee('data-income-section="school"', false);

        foreach (['donation', 'buffet', 'other'] as $type) {
            $this->actingAs($cashier)->get(route("dashboard.finance.income.{$type}"))
                ->assertRedirect();
        }
        $this->actingAs($cashier)->get(route('dashboard.finance.income.revenue.create', ['type' => 'buffet']))->assertForbidden();
        $this->actingAs($cashier)->get(route('dashboard.finance.income.revenue.create', ['type' => 'donation']))->assertForbidden();
        $this->actingAs($cashier)->get(route('dashboard.finance.income.revenue.create'))->assertForbidden();
        $this->actingAs($cashier)
            ->post(route('dashboard.finance.income.revenue.store'), $this->genericPayload($this->category(RevenueCategory::CODE_OTHER, 'Прочее')))
            ->assertForbidden();
        $this->assertSame(0, RevenueEntry::count());
    }

    public function test_reception_sees_no_posting_cards_and_every_posting_destination_is_forbidden(): void
    {
        $reception = $this->user('reception');

        $this->assertSame([], $this->visibleCards($reception));

        $this->actingAs($reception)->get(route('dashboard.quick-registration.create'))->assertForbidden();
        $this->actingAs($reception)->get(route('dashboard.students.unified-collection.create', $this->student))->assertForbidden();
        $this->actingAs($reception)->get(route('dashboard.finance.income.stolovaya'))->assertForbidden();
        $this->actingAs($reception)->get(route('dashboard.employee-stolovaya.create'))->assertForbidden();
        $this->actingAs($reception)->get(route('dashboard.finance.income.revenue.create'))->assertForbidden();
    }

    public function test_school_income_cards_follow_manage_revenues_alone(): void
    {
        $this->assertSame(['buffet', 'donation', 'other'], $this->visibleCards($this->receptionWith('manage revenues')));
    }

    // ----- C. Stolovaya grouping ------------------------------------------

    public function test_actor_allowed_on_both_sides_sees_both_chooser_options(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.stolovaya'));

        $response->assertOk()->assertViewIs('dashboard.finance.income.stolovaya');
        $response->assertSee('data-stolovaya-option="student"', false);
        $response->assertSee('data-stolovaya-option="employee"', false);
        $response->assertSee('href="'.route('dashboard.finance.income.students').'"', false);
        $response->assertSee('href="'.route('dashboard.employee-stolovaya.create').'"', false);
    }

    public function test_employee_only_actor_goes_straight_to_employee_stolovaya_and_cannot_reach_student_posting(): void
    {
        $employeeOnly = $this->receptionWith('manage employee stolovaya');

        $this->assertSame(['stolovaya'], $this->visibleCards($employeeOnly));
        $this->actingAs($employeeOnly)->get(route('dashboard.finance.income.stolovaya'))
            ->assertRedirect(route('dashboard.employee-stolovaya.create'));
        $this->actingAs($employeeOnly)->get(route('dashboard.employee-stolovaya.create'))->assertOk();
        $this->actingAs($employeeOnly)->get(route('dashboard.students.stolovaya.create', $this->student))->assertForbidden();
    }

    public function test_student_only_actor_goes_straight_to_student_search_and_cannot_reach_employee_posting(): void
    {
        $studentOnly = $this->receptionWith('manage invoices');

        $this->assertSame(['payment', 'registration', 'stolovaya'], $this->visibleCards($studentOnly));
        $this->actingAs($studentOnly)->get(route('dashboard.finance.income.stolovaya'))
            ->assertRedirect(route('dashboard.finance.income.students'));
        $this->actingAs($studentOnly)->get(route('dashboard.students.stolovaya.create', $this->student))->assertOk();
        $this->actingAs($studentOnly)->get(route('dashboard.employee-stolovaya.create'))->assertForbidden();
    }

    public function test_actor_with_neither_stolovaya_permission_has_no_usable_stolovaya_entry(): void
    {
        $neither = $this->user('reception');

        $this->assertNotContains('stolovaya', $this->visibleCards($neither));
        $this->actingAs($neither)->get(route('dashboard.finance.income.stolovaya'))->assertForbidden();
    }

    // ----- D. Generic revenue: controlled + legacy categories --------------

    public function test_generic_revenue_form_offers_none_of_the_controlled_or_legacy_categories(): void
    {
        $excluded = [
            $this->category(RevenueCategory::CODE_SCHOOL_FOOD, 'Школьное питание'),
            $this->category(RevenueCategory::CODE_BUFFET, 'Буфет'),
            $this->category(RevenueCategory::CODE_DONATION, 'Пожертвования'),
            $this->category(RevenueCategory::CODE_CAFETERIA, 'Кафетерий'),
        ];
        $other = $this->category(RevenueCategory::CODE_OTHER, 'Прочее');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.revenue.create'))->assertOk();

        $offered = $response->viewData('categories')->pluck('id')->all();
        $this->assertContains($other->id, $offered);
        foreach ($excluded as $category) {
            $this->assertNotContains($category->id, $offered, $category->code);
        }
    }

    public function test_crafted_generic_post_in_any_controlled_or_legacy_category_is_rejected_with_zero_writes(): void
    {
        $codes = [
            RevenueCategory::CODE_SCHOOL_FOOD => 'Школьное питание',
            RevenueCategory::CODE_BUFFET => 'Буфет',
            RevenueCategory::CODE_DONATION => 'Пожертвования',
            RevenueCategory::CODE_CAFETERIA => 'Кафетерий',
        ];

        foreach ($codes as $code => $name) {
            $category = $this->category($code, $name);
            $before = [RevenueEntry::count(), CashTransaction::count()];

            $this->actingAs($this->accountant)
                ->post(route('dashboard.finance.income.revenue.store'), $this->genericPayload($category))
                ->assertSessionHasErrors('revenue_category_id');

            $this->assertSame($before, [RevenueEntry::count(), CashTransaction::count()], $code);
        }
    }

    public function test_crafted_cafeteria_post_gets_the_legacy_message(): void
    {
        $cafeteria = $this->category(RevenueCategory::CODE_CAFETERIA, 'Кафетерий');

        $this->actingAs($this->accountant)
            ->post(route('dashboard.finance.income.revenue.store'), $this->genericPayload($cafeteria))
            ->assertSessionHasErrors(['revenue_category_id' => 'Категория «Кафетерий» устарела и недоступна для новых записей — используйте «Буфет» или подходящую категорию.']);
    }

    public function test_a_residual_category_still_posts_through_the_generic_form(): void
    {
        $other = $this->category(RevenueCategory::CODE_OTHER, 'Прочее');

        $this->actingAs($this->accountant)
            ->post(route('dashboard.finance.income.revenue.store'), $this->genericPayload($other))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame($other->id, RevenueEntry::sole()->revenue_category_id);
        $this->assertSame(1, CashTransaction::count());
    }

    public function test_dedicated_buffet_and_donation_flows_still_post_their_locked_category(): void
    {
        foreach (['buffet' => RevenueCategory::CODE_BUFFET, 'donation' => RevenueCategory::CODE_DONATION] as $type => $code) {
            $category = $this->category($code, ucfirst($type));

            $this->actingAs($this->accountant)
                ->post(route('dashboard.finance.income.revenue.store'), $this->genericPayload($category, ['type' => $type]))
                ->assertSessionHasNoErrors()
                ->assertRedirect();

            $this->assertTrue(RevenueEntry::where('revenue_category_id', $category->id)->exists(), $type);
        }
    }

    public function test_existing_cafeteria_entries_remain_readable_and_the_category_is_untouched(): void
    {
        $cafeteria = $this->category(RevenueCategory::CODE_CAFETERIA, 'Кафетерий');
        $entryId = DB::table('revenue_entries')->insertGetId([
            'revenue_category_id' => $cafeteria->id, 'amount' => '75.00', 'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id, 'payment_method' => 'cash', 'status' => RevenueEntry::STATUS_DRAFT,
            'created_by' => $this->accountant->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->accountant)->get(route('dashboard.finance.income.revenue.show', $entryId))->assertOk();
        $this->actingAs($this->accountant)->get(route('dashboard.finance.income.revenue.index'))->assertOk()->assertSee('Кафетерий');
        $this->assertTrue((bool) $cafeteria->fresh()->is_active);
        $this->assertSame(RevenueCategory::CODE_CAFETERIA, $cafeteria->fresh()->code);
    }

    // ----- E. Payment / registration routing -------------------------------

    public function test_student_payment_card_enters_payment_context_with_unified_collection_primary(): void
    {
        $this->landing($this->accountant)
            ->assertSee('href="'.route('dashboard.finance.income.students', ['context' => 'payment']).'"', false);

        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.students', ['context' => 'payment']))
            ->assertOk()
            ->assertSee(__('finance_workspace.income_students_title_payment'))
            ->assertSee(route('dashboard.students.unified-collection.create', $this->student), false);
    }

    public function test_new_student_card_enters_the_existing_quick_registration_flow(): void
    {
        $this->landing($this->accountant)
            ->assertSee('href="'.route('dashboard.quick-registration.create').'"', false)
            ->assertSee(__('finance_workspace.income_type_registration'));

        $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()
            ->assertSee('Существующий ученик');
    }

    public function test_removed_additional_service_entry_points_still_exist_and_keep_their_authorization(): void
    {
        $this->actingAs($this->accountant)->get(route('dashboard.students.add-service', $this->student))->assertOk();
        $this->actingAs($this->accountant)->get(route('dashboard.students.charge.create', $this->student))->assertOk();
        $this->actingAs($this->user('reception'))->get(route('dashboard.students.add-service', $this->student))->assertForbidden();
        $this->actingAs($this->user('reception'))->get(route('dashboard.students.charge.create', $this->student))->assertForbidden();
    }
}
