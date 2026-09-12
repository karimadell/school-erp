<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\InvoicePayment;
use App\Models\PaymentRefund;
use App\Models\TeacherSalary;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceRefundService;
use App\Services\Finance\Reporting\FinanceReportingService;
use App\Services\Finance\Reporting\FinanceSourceType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Numbered scenarios directly required by the Combined Finance Reporting
 * V1 task instructions. Covers IN/OUT/net math, student payment/refund
 * handling, source/account/date/method filtering, safe degradation when
 * Expenses/Revenues are not integrated, read-only account balances, and
 * N+1 protection.
 */
class FinanceReportingServiceTest extends FinanceOperationsTestCase
{
    private FinanceReportingService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = app(FinanceReportingService::class);
    }

    /**
     * Raw SQL SUM() results (unlike this service's own bcsub()-computed
     * fields such as `net`) are not run back through bcmath before being
     * returned, matching the codebase's existing reporting convention
     * (e.g. Cash\CashTransactionController::reports()). Under SQLite
     * (this test suite) SUM() drops trailing zero-scale ("100" instead of
     * "100.00"); MySQL's DECIMAL SUM preserves it. Normalize both sides
     * through bcadd() here so the assertion checks the value, not the
     * test driver's formatting quirk.
     */
    private function assertMoneySame(string $expected, $actual): void
    {
        $this->assertSame(bcadd($expected, '0', 2), bcadd((string) $actual, '0', 2));
    }

    // 1. IN/OUT/net math for the cash movement summary.
    public function test_cash_movement_summary_computes_in_out_and_net(): void
    {
        $invoice = $this->invoice();
        app(InvoicePaymentService::class)->record(
            $invoice->id, $this->cash->id, '1200.00', 'cash', (string) Str::uuid(), $this->accountant,
        );
        CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'amount' => '300.00',
            'type' => CashTransaction::TYPE_OUT, 'category' => CashTransaction::CATEGORY_EXPENSE,
            'description' => 'Расход: Канцелярия',
        ]);

        $summary = $this->reports->cashMovementSummary([]);

        $this->assertMoneySame('1200.00', $summary['totalIn']);
        $this->assertMoneySame('300.00', $summary['totalOut']);
        $this->assertSame('900.00', $summary['net']);
    }

    // 2. Student payment inclusion in cash movement totals.
    public function test_student_payment_is_included_in_cash_movement_in(): void
    {
        $invoice = $this->invoice();
        app(InvoicePaymentService::class)->record(
            $invoice->id, $this->cash->id, '1200.00', 'cash', (string) Str::uuid(), $this->accountant,
        );

        $summary = $this->reports->cashMovementSummary([]);

        $this->assertMoneySame('1200.00', $summary['totalIn']);
    }

    // 3. Student refund subtraction in the student-collections summary.
    public function test_student_collections_summary_subtracts_refunds_for_net(): void
    {
        $invoice = $this->invoice();
        $payment = app(InvoicePaymentService::class)->record(
            $invoice->id, $this->cash->id, '1200.00', 'cash', (string) Str::uuid(), $this->accountant,
        );
        app(InvoiceRefundService::class)->refund(
            $payment->id, '200.00', 'Тестовый возврат', (string) Str::uuid(), $this->accountant,
        );

        $summary = $this->reports->studentCollectionsSummary([]);

        $this->assertMoneySame('1200.00', $summary['grossPayments']);
        $this->assertMoneySame('200.00', $summary['grossRefunds']);
        $this->assertSame('1000.00', $summary['net']);
        $this->assertSame(1, $summary['paymentsCount']);
        $this->assertSame(1, $summary['refundsCount']);
    }

    // 4. Combined Finance Reporting V1 integration: Expenses V1's
    // cash_transactions.expense_id column now exists on this base — the
    // service must keep working exactly the same (expense_id has no
    // fine-grained resolve()/sqlExpression() consumer yet, see
    // FinanceSourceType's class docblock "EXTENSION POINT" — it already
    // resolves correctly via the category='expense' fallback either way).
    public function test_expense_source_integrated_does_not_crash_reporting(): void
    {
        $this->assertTrue(FinanceSourceType::isExpenseSourceIntegrated());

        $summary = $this->reports->cashMovementSummary([]);
        $daily = $this->reports->dailyFinance([]);

        $this->assertIsArray($summary);
        $this->assertIsIterable($daily);
    }

    // 5. Revenue integration: dailyFinance()'s "available" flags now
    // reflect the real merged schema — both flip to true (they gate on
    // Schema::hasColumn(), not on whether a RevenueEntry/Expense actually
    // posted that specific day), never a fabricated zero either way.
    public function test_revenue_source_integrated_reports_available(): void
    {
        $invoice = $this->invoice();
        app(InvoicePaymentService::class)->record(
            $invoice->id, $this->cash->id, '1200.00', 'cash', (string) Str::uuid(), $this->accountant,
        );

        $daily = $this->reports->dailyFinance([]);
        $today = $daily->firstWhere('day', now()->toDateString());

        $this->assertNotNull($today);
        $this->assertTrue($today['other_income_available']);
        $this->assertTrue($today['expenses_available']);
    }

    // 6. The now-integrated providers populate filter dropdowns correctly.
    public function test_integrated_providers_populate_filter_options(): void
    {
        $types = FinanceSourceType::availableTypes();

        $this->assertIsArray($types);
        $this->assertArrayHasKey(FinanceSourceType::NON_TUITION_REVENUE, $types);
    }

    // 7. Account filter scopes cash movement to a single account.
    public function test_cash_movement_summary_filters_by_account(): void
    {
        $other = CashAccount::create(['name' => 'Другая касса', 'type' => 'cash', 'balance' => '0.00', 'is_active' => true]);
        CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'amount' => '100.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'On primary',
        ]);
        CashTransaction::create([
            'cash_account_id' => $other->id, 'amount' => '500.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'On other',
        ]);

        $summary = $this->reports->cashMovementSummary(['cash_account_id' => $this->cash->id]);

        $this->assertMoneySame('100.00', $summary['totalIn']);
    }

    // 8. Date-range boundaries are inclusive on both ends.
    public function test_date_range_filter_is_inclusive_on_both_boundaries(): void
    {
        $inRange = CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'amount' => '10.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Boundary probe',
        ]);
        $inRange->forceFill(['created_at' => '2026-03-05 23:59:59'])->saveQuietly();

        $before = CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'amount' => '20.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Before range',
        ]);
        $before->forceFill(['created_at' => '2026-03-04 00:00:00'])->saveQuietly();

        $after = CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'amount' => '40.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'After range',
        ]);
        $after->forceFill(['created_at' => '2026-03-06 00:00:01'])->saveQuietly();

        $summary = $this->reports->cashMovementSummary(['from' => '2026-03-05', 'to' => '2026-03-05']);

        $this->assertMoneySame('10.00', $summary['totalIn']);
    }

    // 9. Payment-method filter.
    public function test_cash_movement_summary_filters_by_payment_method(): void
    {
        CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'amount' => '10.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'payment_method' => CashTransaction::METHOD_CASH, 'description' => 'Cash',
        ]);
        CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'amount' => '20.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'payment_method' => CashTransaction::METHOD_CARD, 'description' => 'Card',
        ]);

        $summary = $this->reports->cashMovementSummary(['payment_method' => CashTransaction::METHOD_CARD]);

        $this->assertMoneySame('20.00', $summary['totalIn']);
    }

    // 10. Source-type filter.
    public function test_cash_movement_summary_filters_by_source_type(): void
    {
        $invoice = $this->invoice();
        app(InvoicePaymentService::class)->record(
            $invoice->id, $this->cash->id, '1200.00', 'cash', (string) Str::uuid(), $this->accountant,
        );
        CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'amount' => '50.00',
            'type' => CashTransaction::TYPE_OUT, 'category' => CashTransaction::CATEGORY_EXPENSE,
            'description' => 'Расход',
        ]);

        $summary = $this->reports->cashMovementSummary(['source_type' => FinanceSourceType::STUDENT_PAYMENT]);

        $this->assertMoneySame('1200.00', $summary['totalIn']);
        $this->assertMoneySame('0.00', $summary['totalOut']);
    }

    // 11. Account balances are read-only — computing the report performs
    // no writes to cash_accounts or cash_transactions.
    public function test_account_balances_report_does_not_mutate_any_balance(): void
    {
        $before = $this->cash->fresh()->balance;

        $this->reports->accountBalances([]);

        $this->assertSame($before, $this->cash->fresh()->balance);
    }

    // 12. No student-collections contamination from unrelated cash
    // movements (e.g. a plain expense or transfer must never appear in
    // studentCollectionsSummary, which only reads InvoicePayment/PaymentRefund).
    public function test_student_collections_summary_excludes_unrelated_cash_transactions(): void
    {
        CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'amount' => '9999.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Unrelated other income',
        ]);

        $summary = $this->reports->studentCollectionsSummary([]);

        $this->assertMoneySame('0.00', $summary['grossPayments']);
        $this->assertSame(0, $summary['paymentsCount']);
    }

    // 13. Multiple accounts are each represented independently in
    // accountBalances().
    public function test_account_balances_lists_each_account_independently(): void
    {
        $other = CashAccount::create(['name' => 'Другая касса', 'type' => 'cash', 'balance' => '0.00', 'is_active' => true]);
        CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'amount' => '100.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Primary',
        ]);
        CashTransaction::create([
            'cash_account_id' => $other->id, 'amount' => '500.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Other',
        ]);

        $balances = $this->reports->accountBalances([])->keyBy(fn ($row) => $row['account']->id);

        $this->assertMoneySame('100.00', $balances[$this->cash->id]['total_in']);
        $this->assertMoneySame('500.00', $balances[$other->id]['total_in']);
    }

    // 14. Empty-state behavior — no transactions at all yields zeroed,
    // non-crashing summaries and empty collections.
    public function test_empty_state_returns_zeroed_summary_and_empty_collections(): void
    {
        CashTransaction::query()->delete();

        $summary = $this->reports->cashMovementSummary([]);
        $collections = $this->reports->studentCollectionsSummary([]);
        $daily = $this->reports->dailyFinance([]);

        $this->assertMoneySame('0.00', $summary['totalIn']);
        $this->assertMoneySame('0.00', $summary['totalOut']);
        $this->assertSame('0.00', $summary['net']);
        $this->assertMoneySame('0.00', $collections['grossPayments']);
        $this->assertTrue($daily->isEmpty());
    }

    // 15. Ambiguous multi-source CashTransaction fails safe when used as a
    // source-type filter value too — filtering by the taxonomy's own
    // AMBIGUOUS constant surfaces it rather than folding it into another
    // bucket.
    public function test_ambiguous_transaction_is_filterable_as_its_own_source_type(): void
    {
        $invoice = $this->invoice();
        $payment = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id,
            'amount' => '1200.00', 'payment_method' => 'cash', 'paid_at' => now(),
        ]);
        $payroll = TeacherSalary::create([
            'employee_name' => 'Тест', 'base_salary' => '10.00',
            'bonus' => '0.00', 'allowances' => '0.00', 'deductions' => '0.00', 'salary_month' => now(),
        ]);
        CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'invoice_payment_id' => $payment->id,
            'teacher_salary_id' => $payroll->id, 'amount' => '10.00',
            'type' => CashTransaction::TYPE_OUT, 'category' => CashTransaction::CATEGORY_EXPENSE,
            'description' => 'Ambiguity probe',
        ]);

        $summary = $this->reports->cashMovementSummary(['source_type' => FinanceSourceType::AMBIGUOUS]);
        $studentPaymentSummary = $this->reports->cashMovementSummary(['source_type' => FinanceSourceType::STUDENT_PAYMENT]);

        $this->assertMoneySame('10.00', $summary['totalOut']);
        $this->assertMoneySame('0.00', $studentPaymentSummary['totalOut']);
    }

    // 16. Query-count / N+1 protection: the drilldown page issues a bounded
    // number of queries regardless of how many transactions/refunds are on
    // the page (eager loading + one batched refund lookup, not per-row).
    public function test_transactions_drilldown_is_n_plus_one_safe(): void
    {
        $invoice = $this->invoice('12000.00');
        for ($i = 0; $i < 5; $i++) {
            $payment = app(InvoicePaymentService::class)->record(
                $invoice->id, $this->cash->id, '100.00', 'cash', (string) Str::uuid(), $this->accountant,
            );
            app(InvoiceRefundService::class)->refund(
                $payment->id, '10.00', 'Частичный возврат', (string) Str::uuid(), $this->accountant,
            );
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $page = $this->reports->transactionsDrilldown([], perPage: 50);

        // A handful of fixed queries (paginated list + count + eager loads
        // + one batched refund lookup) regardless of row count — not one
        // query per transaction/refund. 5 payments + 5 refund transactions
        // = 10 rows; a per-row N+1 would blow well past this ceiling.
        $this->assertLessThan(15, $queryCount);
        $this->assertGreaterThan(0, $page->count());
    }

    // 17. Daily finance is sorted newest first.
    public function test_daily_finance_is_sorted_newest_first(): void
    {
        $older = CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'amount' => '10.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Older',
        ]);
        $older->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $newer = CashTransaction::create([
            'cash_account_id' => $this->cash->id, 'amount' => '20.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Newer',
        ]);
        $newer->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $days = $this->reports->dailyFinance([])->pluck('day')->values();

        $this->assertTrue($days->first() > $days->last());
    }

    // 18. Refund without a matching cash transaction batch key still
    // resolves safely (empty refund lookup collection, no crash) — proves
    // the batched whereIn() path degrades correctly when there is nothing
    // to look up.
    public function test_drilldown_with_no_refunds_does_not_crash_the_batch_lookup(): void
    {
        $invoice = $this->invoice();
        app(InvoicePaymentService::class)->record(
            $invoice->id, $this->cash->id, '1200.00', 'cash', (string) Str::uuid(), $this->accountant,
        );

        $page = $this->reports->transactionsDrilldown([]);

        $this->assertSame(1, $page->count());
        $this->assertNull($page->first()->reporting_refund);
    }

    // 19. Never-mixed date fields: a CashTransaction backdated via
    // created_at is picked up by cash-movement filtering on that date,
    // while an InvoicePayment backdated via paid_at (with a distinct
    // created_at) is picked up by student-collections filtering on paid_at
    // — proving the two reports never silently swap date fields.
    public function test_cash_and_collections_reports_use_their_own_distinct_date_fields(): void
    {
        $invoice = $this->invoice();
        $payment = app(InvoicePaymentService::class)->record(
            $invoice->id, $this->cash->id, '1200.00', 'cash', (string) Str::uuid(), $this->accountant,
        );
        // Backdate the financial event date only; created_at (today) stays
        // untouched on both the payment and its cash transaction.
        $payment->forceFill(['paid_at' => '2026-01-10'])->saveQuietly();

        $collectionsOnEventDate = $this->reports->studentCollectionsSummary(['from' => '2026-01-10', 'to' => '2026-01-10']);
        $collectionsToday = $this->reports->studentCollectionsSummary(['from' => now()->toDateString(), 'to' => now()->toDateString()]);
        $cashOnEventDate = $this->reports->cashMovementSummary(['from' => '2026-01-10', 'to' => '2026-01-10']);
        $cashToday = $this->reports->cashMovementSummary(['from' => now()->toDateString(), 'to' => now()->toDateString()]);

        $this->assertMoneySame('1200.00', $collectionsOnEventDate['grossPayments']);
        $this->assertMoneySame('0.00', $collectionsToday['grossPayments']);
        $this->assertMoneySame('0.00', $cashOnEventDate['totalIn']);
        $this->assertMoneySame('1200.00', $cashToday['totalIn']);
    }

    // --- Corrective pass: opening/closing balance, anchored on the live
    // CashAccount.balance rather than a zero-starting-point assumption. ---

    // 20. Non-zero starting balance, no transactions at all: opening and
    // closing must both equal the seeded balance, not 0.00.
    public function test_opening_and_closing_balance_respect_a_non_zero_starting_balance(): void
    {
        $account = CashAccount::create(['name' => 'Seeded', 'type' => 'cash', 'balance' => '5000.00', 'is_active' => true]);

        $summary = $this->reports->cashMovementSummary([
            'cash_account_id' => $account->id, 'from' => now()->toDateString(), 'to' => now()->toDateString(),
        ]);

        $this->assertMoneySame('5000.00', $summary['openingBalance']);
        $this->assertMoneySame('5000.00', $summary['closingBalance']);
    }

    // 21. IN during the period moves the closing balance up from the
    // (correctly non-zero) opening balance.
    public function test_closing_balance_reflects_in_movement_during_period(): void
    {
        $account = CashAccount::create(['name' => 'Seeded', 'type' => 'cash', 'balance' => '1000.00', 'is_active' => true]);
        CashTransaction::create([
            'cash_account_id' => $account->id, 'amount' => '200.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'In during period',
        ]);

        $summary = $this->reports->cashMovementSummary([
            'cash_account_id' => $account->id, 'from' => now()->toDateString(), 'to' => now()->toDateString(),
        ]);

        $this->assertMoneySame('1200.00', $summary['closingBalance']);
        $this->assertMoneySame('1000.00', $summary['openingBalance']);
    }

    // 22. OUT during the period moves the closing balance down from the
    // opening balance.
    public function test_closing_balance_reflects_out_movement_during_period(): void
    {
        $account = CashAccount::create(['name' => 'Seeded', 'type' => 'cash', 'balance' => '500.00', 'is_active' => true]);
        CashTransaction::create([
            'cash_account_id' => $account->id, 'amount' => '100.00',
            'type' => CashTransaction::TYPE_OUT, 'category' => CashTransaction::CATEGORY_EXPENSE,
            'description' => 'Out during period',
        ]);

        $summary = $this->reports->cashMovementSummary([
            'cash_account_id' => $account->id, 'from' => now()->toDateString(), 'to' => now()->toDateString(),
        ]);

        $this->assertMoneySame('400.00', $summary['closingBalance']);
        $this->assertMoneySame('500.00', $summary['openingBalance']);
    }

    // 23. A transaction dated AFTER the selected period must not leak into
    // that period's closing balance — the account's live balance already
    // includes it, so it must be subtracted back out.
    public function test_closing_balance_excludes_movement_after_the_period(): void
    {
        $account = CashAccount::create(['name' => 'Seeded', 'type' => 'cash', 'balance' => '0.00', 'is_active' => true]);
        $future = CashTransaction::create([
            'cash_account_id' => $account->id, 'amount' => '300.00',
            'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Dated after the report period',
        ]);
        $future->forceFill(['created_at' => now()->addDays(5)])->saveQuietly();

        $summary = $this->reports->cashMovementSummary([
            'cash_account_id' => $account->id, 'from' => now()->toDateString(), 'to' => now()->toDateString(),
        ]);

        $this->assertSame('300.00', (string) $account->fresh()->balance);
        $this->assertMoneySame('0.00', $summary['closingBalance']);
        $this->assertMoneySame('0.00', $summary['openingBalance']);
    }

    // 24. With no account filter, opening/closing balance aggregates the
    // live balance across every account, each independently correct.
    public function test_opening_and_closing_balance_aggregate_multiple_accounts(): void
    {
        CashAccount::create(['name' => 'A', 'type' => 'cash', 'balance' => '1000.00', 'is_active' => true]);
        CashAccount::create(['name' => 'B', 'type' => 'cash', 'balance' => '2000.00', 'is_active' => true]);
        // $this->cash (from the shared fixture) starts at 0.00.

        $summary = $this->reports->cashMovementSummary(['from' => now()->toDateString(), 'to' => now()->toDateString()]);

        $this->assertMoneySame('3000.00', $summary['closingBalance']);
        $this->assertMoneySame('3000.00', $summary['openingBalance']);
    }

    // 25. accountBalances()'s current_balance stays untouched — it still
    // reads the live column directly, never the opening/closing derivation.
    public function test_account_balances_current_balance_is_unaffected_by_the_opening_closing_fix(): void
    {
        $account = CashAccount::create(['name' => 'Seeded', 'type' => 'cash', 'balance' => '750.00', 'is_active' => true]);

        $balances = $this->reports->accountBalances([])->keyBy(fn ($row) => $row['account']->id);

        $this->assertMoneySame('750.00', $balances[$account->id]['current_balance']);
    }

    // --- Corrective pass: student collections refund semantics aligned
    // with FinanceCollectionsController's own convention. ---

    // 26. Payment inside the filtered period, refund dated OUTSIDE it: the
    // refund must still be attributed to this period (matching Поступления),
    // not excluded merely because refunded_at falls outside the range.
    public function test_collections_summary_includes_refund_dated_outside_period_when_payment_is_inside(): void
    {
        $invoice = $this->invoice();
        $payment = app(InvoicePaymentService::class)->record(
            $invoice->id, $this->cash->id, '1200.00', 'cash', (string) Str::uuid(), $this->accountant,
        );
        $payment->forceFill(['paid_at' => '2026-03-15'])->saveQuietly();

        $refund = app(InvoiceRefundService::class)->refund(
            $payment->id, '200.00', 'Возврат позже периода', (string) Str::uuid(), $this->accountant,
        );
        $refund->forceFill(['refunded_at' => '2026-04-20'])->saveQuietly();

        $summary = $this->reports->studentCollectionsSummary(['from' => '2026-03-01', 'to' => '2026-03-31']);

        $this->assertMoneySame('1200.00', $summary['grossPayments']);
        $this->assertMoneySame('200.00', $summary['grossRefunds']);
        $this->assertMoneySame('1000.00', $summary['net']);
    }

    // 27. Payment dated OUTSIDE the filtered period, refund dated inside
    // it: the payment (and therefore its refund) must be excluded — a
    // March-only filter never includes a February payment merely because
    // it happened to be refunded in March.
    public function test_collections_summary_excludes_payment_outside_period_even_if_its_refund_is_inside(): void
    {
        $invoice = $this->invoice();
        $payment = app(InvoicePaymentService::class)->record(
            $invoice->id, $this->cash->id, '1200.00', 'cash', (string) Str::uuid(), $this->accountant,
        );
        $payment->forceFill(['paid_at' => '2026-02-10'])->saveQuietly();

        $refund = app(InvoiceRefundService::class)->refund(
            $payment->id, '200.00', 'Возврат в марте', (string) Str::uuid(), $this->accountant,
        );
        $refund->forceFill(['refunded_at' => '2026-03-15'])->saveQuietly();

        $summary = $this->reports->studentCollectionsSummary(['from' => '2026-03-01', 'to' => '2026-03-31']);

        $this->assertMoneySame('0.00', $summary['grossPayments']);
        $this->assertMoneySame('0.00', $summary['grossRefunds']);
        $this->assertSame(0, $summary['paymentsCount']);
        $this->assertSame(0, $summary['refundsCount']);
    }

    // --- Corrective pass: daily-finance schema-introspection performance. ---

    // 28. Schema::hasColumn() must not be re-run per day row — the count
    // stays constant regardless of how many days the range spans.
    public function test_daily_finance_schema_introspection_is_bounded_across_a_wide_range(): void
    {
        for ($i = 0; $i < 35; $i++) {
            $t = CashTransaction::create([
                'cash_account_id' => $this->cash->id, 'amount' => '10.00',
                'type' => CashTransaction::TYPE_IN, 'category' => CashTransaction::CATEGORY_INCOME,
                'description' => 'Day '.$i,
            ]);
            $t->forceFill(['created_at' => now()->subDays($i)])->saveQuietly();
        }

        $schemaQueries = 0;
        DB::listen(function ($query) use (&$schemaQueries) {
            // SQLite's Schema::hasColumn() issues a PRAGMA statement;
            // MySQL/PostgreSQL query information_schema — either way it is
            // clearly distinguishable from the plain SELECT/aggregate
            // queries this method also runs.
            if (str_contains(strtolower($query->sql), 'pragma') || str_contains(strtolower($query->sql), 'information_schema')) {
                $schemaQueries++;
            }
        });

        $daily = $this->reports->dailyFinance([]);

        $this->assertSame(35, $daily->count());
        // Combined Finance Reporting V1 integration: the bound moved from
        // 2 to 3 for a purely mechanical reason, not a regression —
        // isRevenueSourceIntegrated() is `Schema::hasColumn(revenue_entry_id)
        // && Schema::hasColumn(reversed_revenue_entry_id)`; on the old,
        // not-yet-integrated base the first check was false and PHP's `&&`
        // short-circuited before the second ever ran (1 query, not 2).
        // Now that both columns are real, both checks run (2 queries) +
        // isExpenseSourceIntegrated()'s 1 query = 3. The actual property
        // under test — constant regardless of the 35-row date range, never
        // one Schema query per row — still holds exactly as before.
        $this->assertLessThanOrEqual(3, $schemaQueries);
    }

    // --- Combined Finance Reporting V1 integration: revenue reversal
    // precedence, now proven against the REAL merged Non-Tuition Revenues
    // V1 schema/service instead of temporary test-only columns. ---

    public function test_revenue_reversal_and_original_resolve_correctly(): void
    {
        $this->assertTrue(FinanceSourceType::isRevenueSourceIntegrated());

        $category = \App\Models\RevenueCategory::firstOrCreate(
            ['code' => \App\Models\RevenueCategory::CODE_OTHER],
            ['name_ru' => 'Прочие доходы', 'is_active' => true]
        );
        $entry = app(\App\Services\Finance\RevenueService::class)->create([
            'revenue_category_id' => $category->id,
            'amount' => '400.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
            'status' => \App\Models\RevenueEntry::STATUS_POSTED,
        ], $this->accountant);
        $original = CashTransaction::query()->where('revenue_entry_id', $entry->id)->firstOrFail();

        app(\App\Services\Finance\RevenueService::class)->reverse($entry, $this->accountant, 'Ошибка');
        $reversal = CashTransaction::query()->where('reversed_revenue_entry_id', $entry->id)->firstOrFail();

        // A separate, still-draft RevenueEntry — a valid FK target with no
        // existing cash_transactions row in either column yet, so this
        // fabricated "both columns set on one row" probe doesn't collide
        // with the real entry's own already-used unique columns above.
        $unrelatedDraft = app(\App\Services\Finance\RevenueService::class)->create([
            'revenue_category_id' => $category->id,
            'amount' => '10.00',
            'revenue_date' => today()->toDateString(),
        ], $this->accountant);
        $ambiguous = new CashTransaction([
            'cash_account_id' => $this->cash->id,
            'amount' => '10.00', 'type' => CashTransaction::TYPE_OUT,
            'category' => CashTransaction::CATEGORY_REFUND, 'description' => 'Ambiguity probe',
        ]);
        $ambiguous->forceFill(['revenue_entry_id' => $unrelatedDraft->id, 'reversed_revenue_entry_id' => $unrelatedDraft->id]);
        $ambiguous->save();

        // A revenue post must resolve to NON_TUITION_REVENUE, never the
        // generic OTHER_INCOME its shared category=income would otherwise
        // produce.
        $this->assertSame(FinanceSourceType::NON_TUITION_REVENUE, FinanceSourceType::resolve($original));
        // A reversal must resolve to REVENUE_REVERSAL, never STUDENT_REFUND
        // — its shared category=refund with InvoicePayment refunds must
        // never win.
        $this->assertSame(FinanceSourceType::REVENUE_REVERSAL, FinanceSourceType::resolve($reversal));
        $this->assertSame(FinanceSourceType::AMBIGUOUS, FinanceSourceType::resolve($ambiguous));

        // PHP and SQL must agree for all three rows.
        foreach ([$original, $reversal, $ambiguous] as $transaction) {
            $sqlResult = DB::table('cash_transactions')
                ->selectRaw(FinanceSourceType::sqlCase())
                ->where('id', $transaction->id)
                ->value('source_type');
            $this->assertSame(FinanceSourceType::resolve($transaction), $sqlResult);
        }

        $types = FinanceSourceType::availableTypes();
        $this->assertArrayHasKey(FinanceSourceType::NON_TUITION_REVENUE, $types);
        $this->assertArrayHasKey(FinanceSourceType::REVENUE_REVERSAL, $types);
    }
}
