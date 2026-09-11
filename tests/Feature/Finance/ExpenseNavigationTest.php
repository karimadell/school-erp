<?php

namespace Tests\Feature\Finance;

/**
 * Corrective pass regression: the unified dashboard sidebar must link
 * Расход to the dashboard-native Expenses V1 pages
 * (dashboard.finance.expenses.* etc.), never straight into Filament
 * (/admin/...). See resources/views/layouts/partials/shell-sidebar.blade.php.
 *
 * Finance Workspace UX corrective (2nd pass): Категории расходов and
 * Контрагенты no longer get their own sidebar entries at all — their
 * routes still work (technical fallback + the Expense form's own inline
 * "+ Новая категория"/"+ Новый контрагент" quick-create), but an
 * operational user only ever sees a single "Расход" entry.
 */
class ExpenseNavigationTest extends FinanceOperationsTestCase
{
    public function test_manage_expenses_user_sees_dashboard_native_expenses_link(): void
    {
        $user = $this->user('admin');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee(__('finance_workspace.add_expense'))
            ->assertSee(route('dashboard.finance.expenses.index'), false)
            ->assertDontSee(route('filament.admin.resources.expenses.index'), false);
    }

    public function test_expense_categories_link_is_not_a_standalone_sidebar_entry(): void
    {
        $user = $this->user('admin');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertDontSee(route('dashboard.finance.expense-categories.index'), false)
            ->assertDontSee(route('filament.admin.resources.expense-categories.index'), false);
    }

    public function test_payees_link_is_not_a_standalone_sidebar_entry(): void
    {
        $user = $this->user('admin');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertDontSee(route('dashboard.finance.payees.index'), false)
            ->assertDontSee(route('filament.admin.resources.payees.index'), false);
    }

    public function test_user_without_manage_expenses_does_not_see_expenses_navigation(): void
    {
        $user = $this->user('reception');

        $this->actingAs($user)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertDontSee(route('dashboard.finance.expenses.index'), false)
            ->assertDontSee(route('dashboard.finance.expense-categories.index'), false)
            ->assertDontSee(route('dashboard.finance.payees.index'), false);
    }

    public function test_manage_cash_alone_does_not_expose_expenses_navigation(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo('manage cash');

        $this->actingAs($user)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertDontSee(route('dashboard.finance.expenses.index'), false)
            ->assertDontSee(route('dashboard.cash.expenses'), false);
    }

    // Routes remain fully reachable directly, even though unlinked from the sidebar.
    public function test_expense_category_and_payee_routes_remain_reachable_directly(): void
    {
        $user = $this->user('admin');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)->get(route('dashboard.finance.expense-categories.index'))->assertOk();
        $this->actingAs($user)->get(route('dashboard.finance.payees.index'))->assertOk();
    }
}
