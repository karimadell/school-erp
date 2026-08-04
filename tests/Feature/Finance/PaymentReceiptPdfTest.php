<?php
namespace Tests\Feature\Finance;
use App\Services\Finance\InvoicePaymentService; use Illuminate\Support\Str;
class PaymentReceiptPdfTest extends FinanceOperationsTestCase
{
 public function test_pdf_is_generated_without_mutation():void { $invoice=$this->invoice(); $payment=app(InvoicePaymentService::class)->record($invoice->id,$this->cash->id,'1200.00','card',(string)Str::uuid(),$this->accountant); $count=$invoice->payments()->count(); $this->actingAs($this->accountant)->get(route('dashboard.payments.receipt.pdf',$payment))->assertOk()->assertHeader('content-type','application/pdf'); $this->assertSame($count,$invoice->payments()->count()); }
}
