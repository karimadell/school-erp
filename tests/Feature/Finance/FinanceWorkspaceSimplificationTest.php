<?php

namespace Tests\Feature\Finance;

use App\Models\ExpenseCategory;
use App\Models\Payee;

/**
 * Finance Workspace UX corrective: the operational Finance area collapses
 * into Финансы/Приход/Расход/Касса/Отчёты. This file covers everything
 * that isn't already covered by ExpenseNavigationTest (sidebar regression),
 * FinanceUatUxTest (sidebar simplification), or the pre-existing Expense
 * workflow/service/immutability suites (ExpenseWorkflowTest,
 * ExpenseAtomicCreationTest, ExpenseDashboardUiTest) — none of which this
 * pass touches or weakens.
 */
class FinanceWorkspaceSimplificationTest extends FinanceOperationsTestCase
{
    // 1. Finance Workspace loads in the unified dashboard shell.
    public function test_finance_workspace_loads_in_unified_dashboard_shell(): void
    {
        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.workspace'))
            ->assertOk()
            ->assertViewIs('dashboard.finance.workspace')
            ->assertSee('ui2-shell', false)
            ->assertSee('ui2-sidebar', false)
            ->assertSee(__('finance_workspace.income_today'))
            ->assertSee(__('finance_workspace.expense_today'))
            ->assertSee(__('finance_workspace.net_today'))
            ->assertSee(__('finance_workspace.total_balance'));
    }

    // 5. + Расход reaches the canonical Expense create flow.
    public function test_add_expense_button_reaches_canonical_expense_create_flow(): void
    {
        $manager = $this->user('reception');
        $manager->givePermissionTo(['view invoices', 'manage expenses']);

        $this->actingAs($manager)
            ->get(route('dashboard.finance.workspace'))
            ->assertOk()
            ->assertSee(route('dashboard.finance.expenses.create'), false);

        $this->actingAs($manager)
            ->get(route('dashboard.finance.expenses.create'))
            ->assertOk()
            ->assertViewIs('dashboard.finance.expenses.create');
    }

    // 6. Inline category creation requires 'manage expenses'.
    public function test_inline_category_quick_create_requires_manage_expenses(): void
    {
        $noPermission = $this->user('reception');
        $this->actingAs($noPermission)
            ->postJson(route('dashboard.finance.expense-categories.quick-store'), ['name' => 'Хознужды'])
            ->assertForbidden();

        $this->assertDatabaseMissing('expense_categories', ['name' => 'Хознужды']);
    }

    // 7. Inline payee creation requires 'manage expenses'.
    public function test_inline_payee_quick_create_requires_manage_expenses(): void
    {
        $noPermission = $this->user('reception');
        $this->actingAs($noPermission)
            ->postJson(route('dashboard.finance.payees.quick-store'), ['name' => 'ООО Поставщик'])
            ->assertForbidden();

        $this->assertDatabaseMissing('payees', ['name' => 'ООО Поставщик']);
    }

    // 8. Newly created category appears/selects correctly (JSON contract the form's JS relies on).
    public function test_inline_category_quick_create_returns_id_and_name_for_immediate_selection(): void
    {
        $manager = $this->user('reception');
        $manager->givePermissionTo('manage expenses');

        $response = $this->actingAs($manager)
            ->postJson(route('dashboard.finance.expense-categories.quick-store'), ['name' => 'Хознужды']);

        $response->assertOk()->assertJsonStructure(['id', 'name']);
        $category = ExpenseCategory::where('name', 'Хознужды')->firstOrFail();
        $this->assertSame($category->id, $response->json('id'));
        $this->assertTrue($category->is_active, 'Inline-created category must be immediately usable (active).');
    }

    public function test_inline_category_quick_create_rejects_duplicate_name(): void
    {
        ExpenseCategory::create(['name' => 'Коммунальные услуги', 'is_active' => true]);
        $manager = $this->user('reception');
        $manager->givePermissionTo('manage expenses');

        $this->actingAs($manager)
            ->postJson(route('dashboard.finance.expense-categories.quick-store'), ['name' => 'Коммунальные услуги'])
            ->assertStatus(422);
    }

    // 9. Newly created payee appears/selects correctly.
    public function test_inline_payee_quick_create_returns_id_and_name_for_immediate_selection(): void
    {
        $manager = $this->user('reception');
        $manager->givePermissionTo('manage expenses');

        $response = $this->actingAs($manager)
            ->postJson(route('dashboard.finance.payees.quick-store'), [
                'name' => 'ООО Ромашка',
                'phone' => '+201001112233',
            ]);

        $response->assertOk()->assertJsonStructure(['id', 'name']);
        $payee = Payee::where('name', 'ООО Ромашка')->firstOrFail();
        $this->assertSame($payee->id, $response->json('id'));
        $this->assertTrue($payee->is_active, 'Inline-created payee must be immediately usable (active).');
        $this->assertSame('+201001112233', $payee->phone);
    }

    public function test_inline_payee_quick_create_allows_optional_phone_and_notes(): void
    {
        $manager = $this->user('reception');
        $manager->givePermissionTo('manage expenses');

        $this->actingAs($manager)
            ->postJson(route('dashboard.finance.payees.quick-store'), ['name' => 'Частное лицо'])
            ->assertOk();

        $payee = Payee::where('name', 'Частное лицо')->firstOrFail();
        $this->assertNull($payee->phone);
        $this->assertNull($payee->notes);
    }

    // 14. Приход screen is permission-gated.
    public function test_income_type_screen_requires_view_invoices_permission(): void
    {
        $noPermission = $this->user('teacher');
        $this->actingAs($noPermission)
            ->get(route('dashboard.finance.income.index'))
            ->assertRedirect('/login');

        $authorized = $this->user('reception');
        $authorized->givePermissionTo('view invoices');
        $this->actingAs($authorized)
            ->get(route('dashboard.finance.income.index'))
            ->assertOk()
            ->assertViewIs('dashboard.finance.income.index')
            ->assertSee(__('finance_workspace.income_type_title'));
    }

    public function test_income_placeholder_pages_require_view_invoices_permission(): void
    {
        $authorized = $this->user('reception');
        $authorized->givePermissionTo('view invoices');

        $this->actingAs($authorized)->get(route('dashboard.finance.income.donation'))->assertOk();
        $this->actingAs($authorized)->get(route('dashboard.finance.income.other'))->assertOk();

        $noPermission = $this->user('teacher');
        $this->actingAs($noPermission)->get(route('dashboard.finance.income.donation'))->assertRedirect('/login');
    }

    // 15. Student-related Приход paths reuse existing invoice/payment/registration routes — never a parallel implementation.
    public function test_income_type_screen_routes_student_paths_into_existing_canonical_flows(): void
    {
        $authorized = $this->user('reception');
        $authorized->givePermissionTo(['view invoices', 'manage invoices']);

        $response = $this->actingAs($authorized)->get(route('dashboard.finance.income.index'));

        $response->assertOk()
            ->assertSee(route('dashboard.quick-registration.create'), false)
            ->assertSee(route('dashboard.finance.workspace'), false);
    }

    // 16. No duplicate revenue implementation — the placeholder never writes any record of its own.
    public function test_donation_and_other_income_placeholders_do_not_write_any_records(): void
    {
        $authorized = $this->user('reception');
        $authorized->givePermissionTo('view invoices');

        $countsBefore = [
            'expenses' => \App\Models\Expense::query()->count(),
            'invoices' => \App\Models\Invoice::query()->count(),
            'cash_transactions' => \App\Models\CashTransaction::query()->count(),
        ];

        $this->actingAs($authorized)->get(route('dashboard.finance.income.donation'))->assertOk();
        $this->actingAs($authorized)->get(route('dashboard.finance.income.other'))->assertOk();

        $this->assertSame($countsBefore, [
            'expenses' => \App\Models\Expense::query()->count(),
            'invoices' => \App\Models\Invoice::query()->count(),
            'cash_transactions' => \App\Models\CashTransaction::query()->count(),
        ]);
    }

    // No RevenueEntry/RevenueCategory models exist on this branch — the
    // Non-Tuition Revenue engine lives only on its own separate, unmerged
    // worktree (feature/finance-nontuition-revenues-v1) and this pass must
    // not reimplement any part of it here.
    public function test_no_revenue_models_were_duplicated_into_this_branch(): void
    {
        $this->assertFalse(class_exists(\App\Models\RevenueEntry::class), 'RevenueEntry must not be duplicated here — it belongs to the unmerged Non-Tuition Revenue worktree.');
        $this->assertFalse(class_exists(\App\Models\RevenueCategory::class), 'RevenueCategory must not be duplicated here — it belongs to the unmerged Non-Tuition Revenue worktree.');
    }

    // 17. Cash entry (Касса) remains permission-gated.
    public function test_cash_entry_remains_permission_gated(): void
    {
        $this->actingAs($this->user('teacher'))
            ->get(route('dashboard.cash.operations.index'))
            ->assertRedirect('/login');

        $authorized = $this->user('reception');
        $authorized->givePermissionTo('manage cash');
        $this->actingAs($authorized)->get(route('dashboard.cash.operations.index'))->assertOk();
    }

    // 18. Reports entry (Отчёты) remains permission-gated.
    public function test_reports_entry_remains_permission_gated(): void
    {
        $this->actingAs($this->user('reception'))
            ->get(route('dashboard.cash.reports'))
            ->assertForbidden();

        $authorized = $this->user('reception');
        $authorized->givePermissionTo('view cash reports');
        $this->actingAs($authorized)->get(route('dashboard.cash.reports'))->assertOk();
    }

    // Operational summary cards read the canonical ledger only — never a
    // parallel/duplicated total.
    public function test_operational_summary_cards_reflect_canonical_cash_transactions(): void
    {
        $before = $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.workspace'))
            ->viewData('operationalSummary');

        \App\Models\CashTransaction::create([
            'cash_account_id' => $this->cash->id,
            'type' => \App\Models\CashTransaction::TYPE_IN,
            'category' => \App\Models\CashTransaction::CATEGORY_INCOME,
            'amount' => '123.00',
            'description' => 'Workspace summary probe',
        ]);

        $after = $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.workspace'))
            ->viewData('operationalSummary');

        $this->assertSame(bcadd($before['income_today'], '123.00', 2), $after['income_today']);
    }
}
