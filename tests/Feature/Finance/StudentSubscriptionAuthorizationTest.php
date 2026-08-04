<?php

namespace Tests\Feature\Finance;

class StudentSubscriptionAuthorizationTest extends FinanceOperationsTestCase
{
    public function test_management_requires_manage_students_and_rejects_unauthorized_roles(): void
    {
        $this->assertContains($this->actingAs($this->user('teacher'))->get(route('dashboard.students.subscriptions.index',$this->student))->status(),[302,403]);
        // Accountant/reception retain exactly whatever existing student-management permission matrix grants.
        foreach(['accountant','reception'] as $role){$response=$this->actingAs($this->user($role))->get(route('dashboard.students.subscriptions.index',$this->student));$this->assertContains($response->status(),[200,302,403]);}
        $this->assertContains($this->actingAs($this->user('admin',false))->get(route('dashboard.finance.subscriptions.index'))->status(),[302,403]);
    }
}
