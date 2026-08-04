<?php
namespace Tests\Feature\Finance;
use App\Models\StudentServiceSubscription;
class StudentFinanceAccountTest extends FinanceOperationsTestCase
{
 public function test_account_shows_canonical_invoice_payment_and_service_data():void { $invoice=$this->invoice(); StudentServiceSubscription::create(['enrollment_id'=>$this->enrollment->id,'fee_id'=>$this->fee->id,'start_date'=>'2026-08-01','status'=>'active','negotiated_price'=>'1100.00']); $this->actingAs($this->accountant)->get(route('dashboard.students.finance',$this->student))->assertOk()->assertSee('Финансы ученика')->assertSee($invoice->display_number)->assertSee('1200.00 EGP')->assertSee('Обучение')->assertSee('1100.00 EGP')->assertDontSee('RUB'); }
}
