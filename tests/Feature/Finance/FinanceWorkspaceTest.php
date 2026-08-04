<?php
namespace Tests\Feature\Finance;
class FinanceWorkspaceTest extends FinanceOperationsTestCase
{
 public function test_accountant_searches_workspace_and_canonical_totals_do_not_mutate():void { $invoice=$this->invoice('1200.00','2026-08-01'); $this->actingAs($this->accountant)->get(route('dashboard.finance.workspace',['q'=>'Иванов','overdue'=>1]))->assertOk()->assertSee('Финансовый центр')->assertSee('1 200.00 EGP')->assertSee('Просрочено')->assertSee($this->student->phone); $this->assertSame('1200.00',$invoice->fresh()->total_amount); }
}
