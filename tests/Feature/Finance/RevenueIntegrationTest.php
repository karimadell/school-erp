<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\RevenueEntry;
use App\Services\Finance\InvoicePaymentService;
use Illuminate\Support\Str;

/**
 * Non-Tuition Revenues V1 integration — architecture-level guarantees that
 * this integration does not blur the line between student-ledger income
 * (Invoice/InvoicePayment) and genuinely non-tuition income (RevenueEntry).
 */
class RevenueIntegrationTest extends FinanceOperationsTestCase
{
    // 4. Student payment does NOT use RevenueEntry — the canonical
    // InvoicePaymentService path (reached via "Оплата ученика" ->
    // income.students -> the existing payment form) creates an
    // InvoicePayment/CashTransaction pair only, never a RevenueEntry.
    public function test_student_invoice_payment_never_creates_a_revenue_entry(): void
    {
        $revenueCountBefore = RevenueEntry::query()->count();
        $invoice = $this->invoice('1200.00');

        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            paymentMethod: 'cash',
            amount: '1200.00',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $this->assertInstanceOf(InvoicePayment::class, $payment);
        $this->assertSame($revenueCountBefore, RevenueEntry::query()->count(), 'Student payment must never create a RevenueEntry.');
        $this->assertDatabaseHas('cash_transactions', ['invoice_payment_id' => $payment->id, 'revenue_entry_id' => null]);
    }

    // 5. Student service billing does NOT accidentally use generic
    // RevenueEntry — issuing a student invoice (the canonical "Услуга/
    // дополнительный сбор" backend) creates an Invoice, never a RevenueEntry.
    public function test_student_service_invoice_never_creates_a_revenue_entry(): void
    {
        $revenueCountBefore = RevenueEntry::query()->count();
        $invoiceCountBefore = Invoice::query()->count();

        $invoice = $this->invoice('300.00');

        $this->assertSame($invoiceCountBefore + 1, Invoice::query()->count());
        $this->assertSame($revenueCountBefore, RevenueEntry::query()->count(), 'Student service billing must never create a RevenueEntry.');
        $this->assertNotNull($invoice->student_id);
    }

    // No application code (outside RevenueService itself) ever creates a
    // RevenueEntry directly — already comprehensively proven, with correct
    // comment-stripping (so an explanatory docblock mentioning
    // "RevenueEntry::create()" doesn't false-positive), by
    // RevenueAtomicCreationTest::test_no_application_code_bypasses_the_
    // revenue_service_for_creation, which scans the whole of app/ and
    // therefore already covers RevenueEntryController.php too.

    // 25. Permissions remain isolated: 'manage expenses' does not grant any
    // Revenue capability and vice versa — the two workflows are gated
    // entirely independently, same as Expenses V1 was from every other
    // Finance permission.
    public function test_expense_and_revenue_permissions_are_isolated(): void
    {
        $expenseOnly = $this->user('reception');
        $expenseOnly->givePermissionTo(['manage expenses', 'approve expenses', 'post expenses', 'void expenses']);

        $this->assertFalse($expenseOnly->can('manage revenues'));
        $this->assertFalse($expenseOnly->can('post revenues'));
        $this->assertFalse($expenseOnly->can('reverse revenues'));
        $this->assertFalse($expenseOnly->can('viewAny', RevenueEntry::class));

        $revenueOnly = $this->user('reception');
        $revenueOnly->givePermissionTo(['manage revenues', 'post revenues', 'reverse revenues']);

        $this->assertFalse($revenueOnly->can('manage expenses'));
        $this->assertFalse($revenueOnly->can('approve expenses'));
        $this->assertFalse($revenueOnly->can('post expenses'));
        $this->assertFalse($revenueOnly->can('void expenses'));
        $this->assertFalse($revenueOnly->can('viewAny', \App\Models\Expense::class));

        $this->actingAs($expenseOnly)
            ->get(route('dashboard.finance.income.revenue.index'))
            ->assertForbidden();
        $this->actingAs($revenueOnly)
            ->get(route('dashboard.finance.expenses.index'))
            ->assertForbidden();
    }

    // 21/22. Sidebar/landing page remain simplified — no Revenue-specific
    // sidebar entry was added, and the workspace summary cards continue to
    // read the same canonical CashTransaction ledger a posted RevenueEntry
    // also posts to (proving the two features share one ledger, not two).
    public function test_posted_revenue_reflects_in_workspace_summary_without_a_new_sidebar_entry(): void
    {
        $sidebar = view('layouts.partials.shell-sidebar')->render();
        $this->assertStringNotContainsString('/admin/revenue', $sidebar);
        $this->assertStringNotContainsString('dashboard/finance/income/revenue', $sidebar);

        $before = $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.workspace'))
            ->viewData('operationalSummary');

        app(\App\Services\Finance\RevenueService::class)->create([
            'revenue_category_id' => \App\Models\RevenueCategory::firstOrCreate(
                ['code' => \App\Models\RevenueCategory::CODE_OTHER],
                ['name_ru' => 'Прочие доходы', 'is_active' => true]
            )->id,
            'amount' => '333.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
            'status' => RevenueEntry::STATUS_POSTED,
        ], $this->accountant);

        $after = $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.workspace'))
            ->viewData('operationalSummary');

        $this->assertSame(bcadd($before['income_today'], '333.00', 2), $after['income_today']);
    }
}
