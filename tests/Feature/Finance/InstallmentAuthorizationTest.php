<?php

namespace Tests\Feature\Finance;

class InstallmentAuthorizationTest extends FinanceOperationsTestCase
{
    public function test_existing_finance_permissions_protect_plan_and_report_routes(): void
    {
        $this->actingAs($this->accountant)->get(route('dashboard.finance.payment-plans.index'))->assertOk();
        foreach(['teacher','reception'] as $role)$this->assertContains($this->actingAs($this->user($role))->get(route('dashboard.finance.payment-plans.index'))->status(),[302,403]);
        $this->assertContains($this->actingAs($this->user('admin',false))->get(route('dashboard.finance.installments.index'))->status(),[302,403]);
    }

    public function test_no_delete_route_is_exposed(): void
    {
        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn($route)=>$route->getName()==='dashboard.finance.payment-plans.destroy'));
    }
}
