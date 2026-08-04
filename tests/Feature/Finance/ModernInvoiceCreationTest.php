<?php
namespace Tests\Feature\Finance;
class ModernInvoiceCreationTest extends FinanceOperationsTestCase
{
 public function test_server_price_creates_snapshot_without_payment():void { $payload=['student_id'=>$this->student->id,'academic_year_id'=>$this->year->id,'due_date'=>'2027-01-01','fees'=>[$this->fee->id],'initial_payment_amount'=>'0']; $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store',$this->student),$payload+['unit_price'=>'0.01'])->assertSessionHasErrors('unit_price'); $response=$this->post(route('dashboard.students.invoices.store',$this->student),$payload); $response->assertRedirect(); $this->assertDatabaseHas('invoices',['student_id'=>$this->student->id,'total_amount'=>'1200.00','status'=>'unpaid']); $this->assertDatabaseHas('invoice_items',['amount'=>'1200.00','unit_price'=>'1200.00']); $this->assertDatabaseCount('invoice_payments',0); }
 public function test_empty_invoice_is_rejected():void { $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store',$this->student),['student_id'=>$this->student->id,'academic_year_id'=>$this->year->id,'due_date'=>'2027-01-01','fees'=>[]])->assertSessionHasErrors('fees'); $this->assertDatabaseCount('invoices',0); }
}
