<?php

namespace Tests\Feature\Finance;

use App\Models\StudentServiceSubscription;

class SubscriptionControlWorkspaceTest extends FinanceOperationsTestCase
{
    public function test_control_workspace_filters_and_counts_statuses(): void
    {
        StudentServiceSubscription::create(['enrollment_id'=>$this->enrollment->id,'fee_id'=>$this->fee->id,'start_date'=>'2026-08-01','status'=>'active']);
        $this->actingAs($this->user('admin'))->get(route('dashboard.finance.subscriptions.index',['status'=>'active']))->assertOk()->assertSee('Контроль услуг')->assertSee('Активные')->assertSee('Обучение');
    }
}
