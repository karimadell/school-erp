<?php
namespace Tests\Feature\Finance;
use App\Models\StudentServiceSubscription;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use Illuminate\Support\Str;
class StudentFinanceAccountTest extends FinanceOperationsTestCase
{
 public function test_account_shows_canonical_invoice_payment_and_service_data():void { $invoice=$this->invoice(); StudentServiceSubscription::create(['enrollment_id'=>$this->enrollment->id,'fee_id'=>$this->fee->id,'start_date'=>'2026-08-01','status'=>'active','negotiated_price'=>'1100.00']); $this->actingAs($this->accountant)->get(route('dashboard.students.finance',$this->student))->assertOk()->assertSee('Финансовый счёт ученика')->assertSee('Выставить счёт')->assertSee($invoice->display_number)->assertSee('1200.00 EGP')->assertSee('Обучение')->assertSee('1100.00 EGP')->assertDontSee('RUB'); }

 public function test_account_and_statement_use_net_payments_after_refund():void
 {
  $invoice=$this->invoice('1200.00');
  $payment=app(InvoicePaymentService::class)->record(invoiceId:$invoice->id,cashAccountId:$this->cash->id,amount:'900.00',paymentMethod:'cash',idempotencyKey:(string)Str::uuid(),actor:$this->accountant);
  app(InvoiceRefundService::class)->refund(invoicePaymentId:$payment->id,amount:'200.00',reason:'Correction',idempotencyKey:(string)Str::uuid(),actor:$this->accountant);

  $this->actingAs($this->accountant)->get(route('dashboard.students.finance',$this->student))->assertOk()->assertSee('700.00 EGP')->assertSee('500.00 EGP');
  $this->actingAs($this->accountant)->get(route('dashboard.students.finance.statement',$this->student))->assertOk()->assertSee('Финансовая выписка ученика')->assertSee('700.00')->assertSee('Обучение');
  $this->actingAs($this->accountant)->get(route('dashboard.students.finance.statement.pdf',$this->student))->assertOk()->assertHeader('content-type','application/pdf');
 }
}
