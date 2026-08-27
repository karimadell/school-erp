<?php

namespace Tests\Feature\Finance;

use App\Models\Invoice;
use App\Models\PaymentPlan;
use App\Models\CashAccount;

class InstallmentQuickRegistrationTest extends QuickRegistrationUxTestCase
{
    public function test_quick_registration_creates_selected_plan_without_payment(): void
    {
        $structure=$this->structure(); [$year]= $structure; $fee=$this->fee('Обучение','tuition','1200.00',$year->id);
        $plan=PaymentPlan::create(['name_ru'=>'Три взноса','is_active'=>true]);
        foreach([['Первый взнос',0,'30'],['Второй взнос',30,'30'],['Третий взнос',60,'40']] as $i=>$row)$plan->installments()->create(['name_ru'=>$row[0],'sequence'=>$i+1,'offset_days'=>$row[1],'percentage'=>$row[2]]);
        $payload=$this->payload($structure,$fee,['payment_type'=>'plan','payment_plan_id'=>$plan->id,'services'=>[['fee_id'=>$fee->id,'quantity'=>1,'paid_now'=>'0.00']]]);
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'),$payload)->assertSessionHasNoErrors()->assertRedirect();
        $invoice=Invoice::sole(); $this->assertSame(3,$invoice->installments()->count());
        $this->assertSame(['300.00','300.00','400.00'],$invoice->installments()->pluck('amount')->all());
        $this->assertDatabaseCount('invoice_payments',0);
    }

    public function test_quick_registration_rejects_initial_payment_above_first_stage(): void
    {
        $structure=$this->structure(); [$year]= $structure; $fee=$this->fee('Обучение','tuition','1200.00',$year->id);
        $plan=PaymentPlan::create(['name_ru'=>'План','is_active'=>true]);
        $plan->installments()->create(['name_ru'=>'Первый','sequence'=>1,'offset_days'=>0,'percentage'=>'10']);
        $plan->installments()->create(['name_ru'=>'Второй','sequence'=>2,'offset_days'=>30,'percentage'=>'90']);
        $cash=CashAccount::create(['name'=>'Касса','type'=>'cash','is_active'=>true]);
        $payload=$this->payload($structure,$fee,['payment_type'=>'plan','payment_plan_id'=>$plan->id,'cash_account_id'=>$cash->id,'payment_method'=>'cash','services'=>[['fee_id'=>$fee->id,'quantity'=>1,'paid_now'=>'200.00']]]);
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'),$payload)->assertSessionHasErrors('services');
        // Phase 2 transaction/atomicity rule: this failure happens *after*
        // issuance (invoice, items, subscription) has already run inside the
        // same transaction — nothing from any stage may survive.
        $this->assertDatabaseCount('students',0); $this->assertDatabaseCount('invoices',0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('student_service_subscriptions', 0);
        $this->assertDatabaseCount('invoice_installments', 0);
    }
}
