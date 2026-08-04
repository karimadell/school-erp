<?php
namespace Tests\Feature\Finance;
use App\Services\Finance\InvoicePaymentService; use Illuminate\Support\Str;
class FinanceOperationsImmutabilityTest extends FinanceOperationsTestCase
{
 public function test_rendering_workspace_account_and_receipt_changes_nothing():void { $invoice=$this->invoice(); $payment=app(InvoicePaymentService::class)->record($invoice->id,$this->cash->id,'200.00','bank',(string)Str::uuid(),$this->accountant); $snapshot=[$invoice->fresh()->toArray(),$payment->fresh()->toArray(),$payment->cashTransaction->fresh()->toArray()]; $this->actingAs($this->accountant)->get(route('dashboard.finance.workspace'))->assertOk(); $this->get(route('dashboard.students.finance',$this->student))->assertOk(); $this->get(route('dashboard.payments.receipt',$payment))->assertOk(); $this->assertSame($snapshot,[$invoice->fresh()->toArray(),$payment->fresh()->toArray(),$payment->cashTransaction->fresh()->toArray()]); }
}
