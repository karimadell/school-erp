<?php
namespace Tests\Feature\Finance;
use App\Models\SchoolSetting; use App\Services\Finance\InvoicePaymentService; use Illuminate\Support\Str;
class PaymentReceiptTest extends FinanceOperationsTestCase
{
 public function test_receipt_is_russian_branded_and_immutable():void { $invoice=$this->invoice(); $payment=app(InvoicePaymentService::class)->record($invoice->id,$this->cash->id,'500.00','cash',(string)Str::uuid(),$this->accountant); $settings=SchoolSetting::current(); $before=$payment->getAttributes(); $this->actingAs($this->accountant)->get(route('dashboard.payments.receipt',$payment))->assertOk()->assertSee('КВИТАНЦИЯ ОБ ОПЛАТЕ')->assertSee($settings->school_name)->assertSee($settings->phone_1)->assertSee($settings->phone_2)->assertSee($settings->email)->assertSee($payment->payment_number)->assertSee('700.00 EGP')->assertDontSee('RUB'); $this->assertSame($before,$payment->fresh()->getAttributes()); }
}
