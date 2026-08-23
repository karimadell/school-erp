<?php

namespace Tests\Feature\Finance;

class ExpenseNavigationTest extends FinanceOperationsTestCase
{
    public function test_manage_expenses_user_sees_canonical_filament_expenses_link(): void
    {
        $user = $this->user('admin');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee(__('finance_uat.expenses'))
            ->assertSee(route('filament.admin.resources.expenses.index'), false)
            ->assertDontSee(route('dashboard.cash.expenses'), false);
    }

    public function test_user_without_manage_expenses_does_not_see_expenses_navigation(): void
    {
        $user = $this->user('reception');

        $this->actingAs($user)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertDontSee(route('filament.admin.resources.expenses.index'), false);
    }

    public function test_manage_cash_alone_does_not_expose_expenses_navigation(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo('manage cash');

        $this->actingAs($user)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertDontSee(route('filament.admin.resources.expenses.index'), false)
            ->assertDontSee(route('dashboard.cash.expenses'), false);
    }
}
