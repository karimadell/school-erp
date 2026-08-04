<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Models\InvoiceInstallment;
use App\Models\PaymentPlan;
use App\Services\Finance\InstallmentPlanService;
use App\Services\Finance\InvoicePaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InstallmentPaymentPlanTest extends FinanceOperationsTestCase
{
    public function test_plan_generates_independent_exact_invoice_schedule(): void
    {
        $invoice=$this->invoice('1000.00'); $plan=$this->plan();
        app(InstallmentPlanService::class)->generate($invoice,$plan,'2026-08-01');
        $rows=$invoice->installments()->get();
        $this->assertSame(['Регистрационный взнос','Первый взнос','Второй взнос'],$rows->pluck('name_ru')->all());
        $this->assertSame(['200.00','400.00','400.00'],$rows->pluck('amount')->all());
        $this->assertSame('1000.00',bcadd((string)$rows->sum('amount'),'0',2));
        $plan->installments()->first()->update(['percentage'=>'50.0000']);
        $this->assertSame('200.00',$rows->first()->fresh()->amount);
    }

    public function test_partial_full_overpayment_idempotency_and_cash_posting(): void
    {
        Carbon::setTestNow('2026-09-01');
        $invoice=$this->invoice('1000.00'); app(InstallmentPlanService::class)->generate($invoice,$this->plan(),'2026-08-01');
        $installment=$invoice->installments()->first(); $key=(string)Str::uuid(); $service=app(InvoicePaymentService::class);
        $first=$service->record($invoice->id,$this->cash->id,'100.00','cash',$key,$this->accountant,installmentId:$installment->id);
        $replay=$service->record($invoice->id,$this->cash->id,'100.00','cash',$key,$this->accountant,installmentId:$installment->id);
        $this->assertSame($first->id,$replay->id); $installment=$installment->fresh(); $this->assertSame('partial',$installment->derivedStatus());
        $this->assertSame('100.00',$installment->paid_amount); $this->assertSame('100.00',$installment->remaining_amount);
        $this->assertDatabaseCount('invoice_payments',1); $this->assertDatabaseCount('cash_transactions',1);
        $service->record($invoice->id,$this->cash->id,'100.00','card',(string)Str::uuid(),$this->accountant,installmentId:$installment->id);
        $this->assertSame('paid',$installment->fresh()->derivedStatus());
        try {$service->record($invoice->id,$this->cash->id,'1.00','cash',(string)Str::uuid(),$this->accountant,installmentId:$installment->id);$this->fail('Переплата должна быть отклонена.');}
        catch(ValidationException){$this->assertDatabaseCount('invoice_payments',2);}
        $this->assertSame(2,CashTransaction::count());
    }

    public function test_status_transitions_are_derived_from_due_date_and_balance(): void
    {
        $invoice=$this->invoice('1000.00'); app(InstallmentPlanService::class)->generate($invoice,$this->plan(),'2026-08-01');
        $rows=$invoice->installments()->get();
        $this->assertSame('future',$rows[1]->derivedStatus(Carbon::parse('2026-08-15')));
        $this->assertSame('pending',$rows[1]->derivedStatus(Carbon::parse('2026-09-01')));
        $this->assertSame('overdue',$rows[1]->derivedStatus(Carbon::parse('2026-09-02')));
    }

    public function test_dashboard_plan_management_reports_and_receipt_are_russian(): void
    {
        $this->actingAs($this->accountant)->post(route('dashboard.finance.payment-plans.store'),['name_ru'=>'Регистрация + 2 взноса','sort_order'=>1,'is_active'=>1,
            'installments'=>[['name_ru'=>'Регистрация','offset_days'=>0,'percentage'=>'20'],['name_ru'=>'Первый взнос','offset_days'=>30,'percentage'=>'40'],['name_ru'=>'Второй взнос','offset_days'=>60,'percentage'=>'40']]])->assertRedirect();
        $plan=PaymentPlan::sole(); $invoice=$this->invoice('1000.00'); app(InstallmentPlanService::class)->generate($invoice,$plan,'2026-08-01');
        $payment=app(InvoicePaymentService::class)->record($invoice->id,$this->cash->id,'200.00','cash',(string)Str::uuid(),$this->accountant,installmentId:$invoice->installments()->first()->id);
        $this->get(route('dashboard.students.finance',$this->student))->assertOk()->assertSee('Рассрочка')->assertSee('Первый взнос');
        $this->get(route('dashboard.finance.installments.index'))->assertOk()->assertSee('Контроль рассрочки')->assertSee('Предстоящие');
        $this->get(route('dashboard.payments.receipt',$payment))->assertOk()->assertSee('Этап рассрочки')->assertSee('Регистрация');
    }

    private function plan(): PaymentPlan
    {
        $plan=PaymentPlan::create(['name_ru'=>'Регистрация + 2 взноса','is_active'=>true]);
        foreach([['Регистрационный взнос',0,'20'],['Первый взнос',31,'40'],['Второй взнос',62,'40']] as $i=>$row)$plan->installments()->create(['name_ru'=>$row[0],'sequence'=>$i+1,'offset_days'=>$row[1],'percentage'=>$row[2]]);
        return $plan;
    }
}
