<?php
namespace Tests\Feature\Finance;
use Illuminate\Support\Str;
class ModernPaymentCollectionTest extends FinanceOperationsTestCase
{
 public function test_partial_and_duplicate_submission_post_once():void { $invoice=$this->invoice(); $key=(string)Str::uuid(); $data=['amount'=>'500.00','cash_account_id'=>$this->cash->id,'payment_method'=>'cash','idempotency_key'=>$key]; $this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store',$invoice),$data)->assertRedirect(); $this->post(route('dashboard.invoices.payments.store',$invoice),$data)->assertRedirect(); $this->assertDatabaseCount('invoice_payments',1); $this->assertDatabaseCount('cash_transactions',1); $this->assertSame('partial',$invoice->fresh()->status); $this->assertSame('500.00',$this->cash->fresh()->balance); }
 public function test_overpayment_zero_and_inactive_cash_are_rejected():void { $invoice=$this->invoice(); foreach([['amount'=>'1300','cash_account_id'=>$this->cash->id],['amount'=>'0','cash_account_id'=>$this->cash->id]] as $case){$this->actingAs($this->accountant)->post(route('dashboard.invoices.payments.store',$invoice),$case+['payment_method'=>'cash','idempotency_key'=>(string)Str::uuid()])->assertSessionHasErrors('amount');} $this->cash->update(['is_active'=>false]); $this->post(route('dashboard.invoices.payments.store',$invoice),['amount'=>'100','cash_account_id'=>$this->cash->id,'payment_method'=>'cash','idempotency_key'=>(string)Str::uuid()])->assertSessionHasErrors('cash_account_id'); $this->assertDatabaseCount('invoice_payments',0); }
}
