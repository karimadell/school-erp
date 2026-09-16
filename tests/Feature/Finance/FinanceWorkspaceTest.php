<?php
namespace Tests\Feature\Finance;
class FinanceWorkspaceTest extends FinanceOperationsTestCase
{
 // Search/UX corrective — the global KPI cards this test used to read
 // ('1 200.00 EGP') were removed (they were unscoped to the search and
 // duplicated the Финансы landing page). This now proves the same two
 // things through the redesigned per-student row instead: the search
 // finds the right student with its real remaining/overdue figures, and
 // viewing the page never mutates the underlying invoice.
 public function test_accountant_searches_workspace_and_canonical_totals_do_not_mutate():void { $invoice=$this->invoice('1200.00','2026-08-01'); $this->actingAs($this->accountant)->get(route('dashboard.finance.income.students',['q'=>'Иванов','overdue'=>1]))->assertOk()->assertSee('1200.00 EGP')->assertSee('Просрочено')->assertSee($this->student->phone); $this->assertSame('1200.00',$invoice->fresh()->total_amount); }
}
