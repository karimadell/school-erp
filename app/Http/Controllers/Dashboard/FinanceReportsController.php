<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Services\Finance\Reporting\FinanceReportingService;
use App\Services\Finance\Reporting\FinanceSourceType;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Combined Finance Reporting V1 — a read-only reporting surface over the
 * existing canonical Finance data (CashTransaction for cash movement;
 * InvoicePayment/PaymentRefund for student collections — never conflated,
 * see FinanceReportingService's class docblock). No action here mutates
 * financial state; there is no store/update/destroy method in this
 * controller and none should ever be added.
 *
 * Permissions reuse the existing Finance viewing permissions rather than
 * inventing a new one: 'view cash reports' gates the cash-movement/
 * account-balance pages (the same permission
 * Cash\CashTransactionController::reports() already requires for
 * equivalent visibility), 'view collections' gates the student-collections
 * page (the same permission FinanceCollectionsController already
 * requires).
 *
 * index() is the one page that can show BOTH domains, so it cannot be
 * gated by a single class-level middleware entry the way the other three
 * are — see its own docblock for the isolation rule.
 *
 * Every action validates 'from'/'to'/filter values before they are parsed
 * or used in a query: a malformed date string or an unknown filter value
 * fails Laravel's normal validation (redirect back with errors) instead of
 * throwing an uncaught Carbon exception or reaching raw SQL.
 */
class FinanceReportsController extends Controller
{
    /** Cash-domain payment methods — CashTransaction's own vocabulary, used to filter cash_transactions.payment_method. */
    private const CASH_PAYMENT_METHODS = [
        CashTransaction::METHOD_CASH => 'Наличные',
        CashTransaction::METHOD_CARD => 'Карта',
        CashTransaction::METHOD_BANK => 'Банковский перевод',
        CashTransaction::METHOD_TRANSFER => 'Перевод',
    ];

    public function __construct(private FinanceReportingService $reports)
    {
        $this->middleware('permission:view cash reports')->only(['cashMovement', 'accountBalances']);
        $this->middleware('permission:view collections')->only(['studentCollections']);
    }

    /**
     * Permission isolation: a user with only 'view cash reports' must see
     * cash-report metrics and nothing from the student-collections domain;
     * a user with only 'view collections' gets the reverse; a user with
     * neither gets 403; a user with both sees both. Each domain's service
     * call is skipped entirely when the user lacks that domain's
     * permission — never queried-then-hidden.
     */
    public function index(Request $request): View
    {
        $canViewCash = $request->user()?->can('view cash reports') ?? false;
        $canViewCollections = $request->user()?->can('view collections') ?? false;
        abort_unless($canViewCash || $canViewCollections, 403);

        $filters = $this->periodFilters($request);

        $cash = $canViewCash ? $this->reports->cashMovementSummary($filters) : null;
        $collections = $canViewCollections ? $this->reports->studentCollectionsSummary($filters) : null;
        $daily = $this->reports->dailyFinance($filters, includeCash: $canViewCash, includeCollections: $canViewCollections);

        return view('dashboard.finance-reports.index', [
            'filters' => $filters,
            'canViewCash' => $canViewCash,
            'canViewCollections' => $canViewCollections,
            'cash' => $cash,
            'collections' => $collections,
            'daily' => $daily,
            'nonStudentAvailable' => [
                'expenses' => $canViewCash && FinanceSourceType::isExpenseSourceIntegrated(),
                'revenues' => $canViewCash && FinanceSourceType::isRevenueSourceIntegrated(),
            ],
        ]);
    }

    public function cashMovement(Request $request): View
    {
        $filters = $this->fullFilters($request);

        $summary = $this->reports->cashMovementSummary($filters);
        $transactions = $this->reports->transactionsDrilldown($filters);

        return view('dashboard.finance-reports.cash-movement', [
            'filters' => $filters,
            'summary' => $summary,
            'transactions' => $transactions,
            'accounts' => CashAccount::query()->orderBy('name')->get(),
            'paymentMethods' => self::CASH_PAYMENT_METHODS,
            'sourceTypes' => FinanceSourceType::availableTypes(),
        ]);
    }

    public function studentCollections(Request $request): View
    {
        $filters = $this->periodFilters($request, includeAccountAndMethod: true);

        $summary = $this->reports->studentCollectionsSummary($filters);

        return view('dashboard.finance-reports.student-collections', [
            'filters' => $filters,
            'summary' => $summary,
            'accounts' => CashAccount::query()->orderBy('name')->get(),
        ]);
    }

    public function accountBalances(Request $request): View
    {
        $filters = $this->periodFilters($request);

        $balances = $this->reports->accountBalances($filters);

        return view('dashboard.finance-reports.account-balances', [
            'filters' => $filters,
            'balances' => $balances,
        ]);
    }

    /**
     * Shared date-range resolution: defaults to the current month when no
     * explicit range is supplied, consistent across every report page so
     * "текущий месяц" always means the same thing. Validates every raw
     * input value in one pass BEFORE any parsing.
     */
    private function periodFilters(Request $request, bool $includeAccountAndMethod = false): array
    {
        $extraRules = [];
        if ($includeAccountAndMethod) {
            $extraRules['cash_account_id'] = 'nullable|integer|exists:cash_accounts,id';
            $extraRules['payment_method'] = 'nullable|in:'.implode(',', array_keys(FinanceCollectionsController::METHOD_LABELS));
        }
        $this->validateFilters($request, $extraRules);

        $filters = [
            'from' => $request->filled('from') ? $request->date('from')->toDateString() : now()->startOfMonth()->toDateString(),
            'to' => $request->filled('to') ? $request->date('to')->toDateString() : now()->endOfMonth()->toDateString(),
        ];

        if ($includeAccountAndMethod) {
            $filters['cash_account_id'] = $request->integer('cash_account_id') ?: null;
            $filters['payment_method'] = $request->filled('payment_method') ? $request->string('payment_method')->toString() : null;
        }

        return array_filter($filters, fn ($value) => $value !== null);
    }

    private function fullFilters(Request $request): array
    {
        $this->validateFilters($request, [
            'cash_account_id' => 'nullable|integer|exists:cash_accounts,id',
            'type' => 'nullable|in:'.CashTransaction::TYPE_IN.','.CashTransaction::TYPE_OUT,
            'payment_method' => 'nullable|in:'.implode(',', array_keys(self::CASH_PAYMENT_METHODS)),
            'source_type' => 'nullable|in:'.implode(',', array_keys(FinanceSourceType::availableTypes())),
        ]);

        $filters = [
            'from' => $request->filled('from') ? $request->date('from')->toDateString() : now()->startOfMonth()->toDateString(),
            'to' => $request->filled('to') ? $request->date('to')->toDateString() : now()->endOfMonth()->toDateString(),
            'cash_account_id' => $request->integer('cash_account_id') ?: null,
            'type' => $request->filled('type') ? $request->string('type')->toString() : null,
            'payment_method' => $request->filled('payment_method') ? $request->string('payment_method')->toString() : null,
            'source_type' => $request->filled('source_type') ? $request->string('source_type')->toString() : null,
        ];

        return array_filter($filters, fn ($value) => $value !== null);
    }

    /**
     * 'from'/'to' must be well-formed dates (never letting a malformed
     * string reach Carbon::parse()) and 'to' must not be before 'from'
     * when both are present. Any additional per-action rules (account/
     * type/method/source-type whitelists) are merged in and validated in
     * the same single pass, so a request is never partially validated.
     */
    private function validateFilters(Request $request, array $extraRules = []): void
    {
        $request->validate(array_merge([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ], $extraRules));
    }
}
