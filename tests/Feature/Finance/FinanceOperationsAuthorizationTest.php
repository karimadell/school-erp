<?php
namespace Tests\Feature\Finance;
class FinanceOperationsAuthorizationTest extends FinanceOperationsTestCase
{
 public function test_current_permissions_protect_operations():void { $invoice=$this->invoice(); $this->actingAs($this->accountant)->get(route('dashboard.finance.workspace'))->assertOk(); $this->actingAs($this->user('reception'))->get(route('dashboard.students.invoices.create',$this->student))->assertForbidden(); $this->actingAs($this->user('teacher'))->get(route('dashboard.finance.workspace'))->assertRedirect('/login'); $this->actingAs($this->user('admin',false))->get(route('dashboard.finance.workspace'))->assertRedirect('/login'); }
}
