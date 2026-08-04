<?php

namespace Tests\Feature\Finance;

use App\Models\StudentServiceSubscription;

class StudentSubscriptionManagementTest extends FinanceOperationsTestCase
{
    public function test_management_pages_and_creation_are_russian_and_do_not_create_finance_records(): void
    {
        $admin=$this->user('admin'); $this->actingAs($admin)->get(route('dashboard.students.subscriptions.index',$this->student))->assertOk()->assertSee('Услуги ученика');
        $before=['invoices'=>\App\Models\Invoice::count(),'invoice_payments'=>\App\Models\InvoicePayment::count()];
        $this->actingAs($admin)->post(route('dashboard.students.subscriptions.store',$this->student),['academic_year_id'=>$this->year->id,'fee_id'=>$this->fee->id,'start_date'=>'2026-08-01','quantity'=>1,'metadata'=>['zone'=>'A']])->assertRedirect();
        $this->assertDatabaseCount('student_service_subscriptions',1); $this->assertSame($before['invoices'],\App\Models\Invoice::count()); $this->assertSame($before['invoice_payments'],\App\Models\InvoicePayment::count());
        $subscription=StudentServiceSubscription::sole(); $this->get(route('dashboard.students.subscriptions.show',[$this->student,$subscription]))->assertOk()->assertSee('Услуга ученика')->assertSee('Активна');
    }
}
