<?php

namespace Tests\Feature\Finance;

/**
 * Corrective pass regression: the unified dashboard sidebar must link
 * Expenses/Categories/Payees to the dashboard-native pages
 * (dashboard.finance.expenses.* etc.), never straight into Filament
 * (/admin/...). See resources/views/layouts/partials/shell-sidebar.blade.php.
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
            ->assertSee(__('finance_uat.expenses'))
            ->assertSee(route('dashboard.finance.expenses.index'), false)
            ->assertDontSee(route('filament.admin.resources.expenses.index'), false);
    }

    public function test_manage_expenses_user_sees_dashboard_native_categories_link(): void
    {
        $user = $this->user('admin');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee(__('expenses.nav_categories'))
            ->assertSee(route('dashboard.finance.expense-categories.index'), false)
            ->assertDontSee(route('filament.admin.resources.expense-categories.index'), false);
    }

    public function test_manage_expenses_user_sees_dashboard_native_payees_link(): void
    {
        $user = $this->user('admin');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee(__('expenses.nav_payees'))
            ->assertSee(route('dashboard.finance.payees.index'), false)
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
}
