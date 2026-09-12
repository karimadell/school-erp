<?php

namespace App\Services\Finance\Reporting;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\InvoicePayment;
use App\Models\PaymentRefund;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Read-only aggregation over the existing canonical Finance data — never a
 * second ledger. Cash-movement figures come from CashTransaction (this
 * project's own source of truth for money movement, unchanged); student
 * collections figures come from InvoicePayment/PaymentRefund (the
 * project's own canonical source for confirmed student money — see
 * FinanceCollectionsController's own class docblock). The two are never
 * conflated: cashMovementSummary()/accountBalances()/dailyFinance()'s cash
 * side never touch InvoicePayment/PaymentRefund business rules, and
 * studentCollectionsSummary() never touches CashTransaction.
 *
 * Date field convention (deliberately never mixed): cash-movement
 * reporting filters on cash_transactions.created_at — the transaction's
 * own timestamp, matching the existing, live
 * Cash\CashTransactionController::reports() convention exactly. Student
 * collections reporting filters on invoice_payments.paid_at /
 * payment_refunds.refunded_at — the actual financial event dates, matching
 * FinanceCollectionsController's own established convention (explicitly
 * NOT created_at there either).
 *
 * No financial state mutation lives here or is reachable from it.
 *
 * Opening/closing balance (cashMovementSummary()): anchored on the live,
 * authoritative CashAccount.balance, never on an assumption that an
 * account started at zero — see closingBalanceAt()'s docblock. Student
 * collections (studentCollectionsSummary()): refund inclusion is
 * attributed by whether a refund's PARENT PAYMENT falls in the filtered
 * set, exactly matching FinanceCollectionsController::totals()'s own
 * established convention — not by the refund's own date — so this page
 * and the canonical Поступления page never silently define "refunds this
 * period" differently for the same filters.
 */
class FinanceReportingService
{
    /**
     * @param  array{from?:string,to?:string,cash_account_id?:int,type?:string,payment_method?:string,source_type?:string}  $filters
     */
    public function cashMovementSummary(array $filters): array
    {
        $base = $this->cashTransactionQuery($filters);

        $totalIn = (string) (clone $base)->where('type', CashTransaction::TYPE_IN)->sum('amount');
        $totalOut = (string) (clone $base)->where('type', CashTransaction::TYPE_OUT)->sum('amount');
        $net = bcsub($totalIn, $totalOut, 2);

        $accountId = $filters['cash_account_id'] ?? null;
        $closingBalance = null;
        $openingBalance = null;
        if (! empty($filters['to'])) {
            $closingBalance = $this->closingBalanceAt($accountId, $filters['to']);

            if (! empty($filters['from'])) {
                $movementWithinPeriod = $this->netMovementBetween($accountId, $filters['from'], $filters['to']);
                $openingBalance = bcsub($closingBalance, $movementWithinPeriod, 2);
            }
        }

        $byAccount = (clone $base)
            ->join('cash_accounts', 'cash_accounts.id', '=', 'cash_transactions.cash_account_id')
            ->selectRaw('
                cash_accounts.id as cash_account_id,
                cash_accounts.name as cash_account_name,
                cash_accounts.balance as current_balance,
                SUM(CASE WHEN cash_transactions.type = ? THEN cash_transactions.amount ELSE 0 END) as total_in,
                SUM(CASE WHEN cash_transactions.type = ? THEN cash_transactions.amount ELSE 0 END) as total_out
            ', [CashTransaction::TYPE_IN, CashTransaction::TYPE_OUT])
            ->groupBy('cash_accounts.id', 'cash_accounts.name', 'cash_accounts.balance')
            ->orderBy('cash_accounts.name')
            ->get();

        $byMethod = (clone $base)
            ->selectRaw('
                payment_method,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as total_in,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as total_out
            ', [CashTransaction::TYPE_IN, CashTransaction::TYPE_OUT])
            ->groupBy('payment_method')
            ->get();

        $bySourceType = (clone $base)
            ->selectRaw(FinanceSourceType::sqlCase().',
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as total_in,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as total_out
            ', [CashTransaction::TYPE_IN, CashTransaction::TYPE_OUT])
            ->groupByRaw(FinanceSourceType::sqlExpression())
            ->get();

        $byDay = (clone $base)
            ->selectRaw('
                DATE(created_at) as day,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as total_in,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as total_out
            ', [CashTransaction::TYPE_IN, CashTransaction::TYPE_OUT])
            ->groupByRaw('DATE(created_at)')
            ->orderByDesc('day')
            ->get();

        return compact('totalIn', 'totalOut', 'net', 'openingBalance', 'closingBalance', 'byAccount', 'byMethod', 'bySourceType', 'byDay');
    }

    /**
     * Refund inclusion mirrors FinanceCollectionsController::totals()
     * exactly: a refund is attributed to this summary because its PARENT
     * PAYMENT is in the filtered set (paid_at/account/method/academic
     * year), never by the refund's own refunded_at date. This is a
     * deliberate choice to keep one single definition of "refunds this
     * period" across both Finance pages — a payment made inside the
     * period but refunded later still counts here (and there); a payment
     * made outside the period is excluded here (and there) even if it
     * happens to be refunded inside the period.
     *
     * @param  array{from?:string,to?:string,cash_account_id?:int,payment_method?:string,academic_year_id?:int}  $filters
     */
    public function studentCollectionsSummary(array $filters): array
    {
        $paymentsQuery = InvoicePayment::query();

        if (! empty($filters['from'])) {
            $paymentsQuery->whereDate('paid_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $paymentsQuery->whereDate('paid_at', '<=', $filters['to']);
        }
        if (! empty($filters['cash_account_id'])) {
            $paymentsQuery->where('cash_account_id', $filters['cash_account_id']);
        }
        if (! empty($filters['payment_method'])) {
            $paymentsQuery->where('payment_method', $filters['payment_method']);
        }
        if (! empty($filters['academic_year_id'])) {
            $paymentsQuery->whereHas('invoice', fn (Builder $q) => $q->where('academic_year_id', $filters['academic_year_id']));
        }

        $paymentIds = (clone $paymentsQuery)->pluck('id');
        $refundsQuery = PaymentRefund::query()->whereIn('invoice_payment_id', $paymentIds);

        $grossPayments = (string) (clone $paymentsQuery)->sum('amount');
        $paymentsCount = (clone $paymentsQuery)->count();
        $grossRefunds = (string) (clone $refundsQuery)->sum('amount');
        $refundsCount = (clone $refundsQuery)->count();
        $net = bcsub($grossPayments, $grossRefunds, 2);

        return compact('grossPayments', 'paymentsCount', 'grossRefunds', 'refundsCount', 'net');
    }

    /**
     * @param  array{from?:string,to?:string}  $filters
     */
    public function accountBalances(array $filters): Collection
    {
        $movementQuery = CashTransaction::query();
        if (! empty($filters['from'])) {
            $movementQuery->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $movementQuery->whereDate('created_at', '<=', $filters['to']);
        }

        $movementByAccount = $movementQuery
            ->selectRaw('
                cash_account_id,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as total_in,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as total_out
            ', [CashTransaction::TYPE_IN, CashTransaction::TYPE_OUT])
            ->groupBy('cash_account_id')
            ->get()
            ->keyBy('cash_account_id');

        return CashAccount::query()->orderBy('name')->get()->map(function (CashAccount $account) use ($movementByAccount) {
            $movement = $movementByAccount->get($account->id);
            $totalIn = (string) ($movement->total_in ?? '0.00');
            $totalOut = (string) ($movement->total_out ?? '0.00');

            return [
                'account' => $account,
                'current_balance' => (string) $account->balance,
                'total_in' => $totalIn,
                'total_out' => $totalOut,
                'net' => bcsub($totalIn, $totalOut, 2),
            ];
        });
    }

    /**
     * $includeCash/$includeCollections let a caller with only one of the
     * two report permissions skip the other domain's queries entirely
     * (rather than querying it and hiding it in the view) — see
     * FinanceReportsController::index()'s permission-isolation handling.
     * A skipped domain's fields come back null (rendered as "—"), never a
     * fabricated zero, so "not authorized to see" is never confused with
     * "genuinely no activity that day".
     *
     * @param  array{from?:string,to?:string}  $filters
     */
    public function dailyFinance(array $filters, bool $includeCash = true, bool $includeCollections = true): Collection
    {
        $cashByDay = collect();
        $paymentsByDay = collect();
        $refundsByDay = collect();

        if ($includeCash) {
            $cashQuery = CashTransaction::query();
            if (! empty($filters['from'])) {
                $cashQuery->whereDate('created_at', '>=', $filters['from']);
            }
            if (! empty($filters['to'])) {
                $cashQuery->whereDate('created_at', '<=', $filters['to']);
            }

            $cashByDay = $cashQuery
                ->selectRaw('
                    DATE(created_at) as day,
                    SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as total_in,
                    SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as total_out
                ', [CashTransaction::TYPE_IN, CashTransaction::TYPE_OUT])
                ->groupByRaw('DATE(created_at)')
                ->get()->keyBy('day');
        }

        if ($includeCollections) {
            $paymentsQuery = InvoicePayment::query();
            $refundsQuery = PaymentRefund::query();
            if (! empty($filters['from'])) {
                $paymentsQuery->whereDate('paid_at', '>=', $filters['from']);
                $refundsQuery->whereDate('refunded_at', '>=', $filters['from']);
            }
            if (! empty($filters['to'])) {
                $paymentsQuery->whereDate('paid_at', '<=', $filters['to']);
                $refundsQuery->whereDate('refunded_at', '<=', $filters['to']);
            }

            $paymentsByDay = $paymentsQuery
                ->selectRaw('DATE(paid_at) as day, SUM(amount) as total, COUNT(*) as cnt')
                ->groupByRaw('DATE(paid_at)')
                ->get()->keyBy('day');

            $refundsByDay = $refundsQuery
                ->selectRaw('DATE(refunded_at) as day, SUM(amount) as total, COUNT(*) as cnt')
                ->groupByRaw('DATE(refunded_at)')
                ->get()->keyBy('day');
        }

        $days = collect($cashByDay->keys())
            ->merge($paymentsByDay->keys())
            ->merge($refundsByDay->keys())
            ->filter()
            ->unique()
            ->sortDesc()
            ->values();

        // Hoisted out of the per-day map() below: each check performs a
        // real Schema::hasColumn() introspection query, so evaluating it
        // per row would multiply query count with the requested date
        // range's width instead of staying constant.
        $revenuesAvailable = $includeCash && FinanceSourceType::isRevenueSourceIntegrated();
        $expensesAvailable = $includeCash && FinanceSourceType::isExpenseSourceIntegrated();

        return $days->map(function (string $day) use ($cashByDay, $paymentsByDay, $refundsByDay, $revenuesAvailable, $expensesAvailable, $includeCash, $includeCollections) {
            $cash = $cashByDay->get($day);
            $payments = $paymentsByDay->get($day);
            $refunds = $refundsByDay->get($day);

            $totalIn = $includeCash ? (string) ($cash->total_in ?? '0.00') : null;
            $totalOut = $includeCash ? (string) ($cash->total_out ?? '0.00') : null;

            return [
                'day' => $day,
                'total_in' => $totalIn,
                'total_out' => $totalOut,
                'net' => $includeCash ? bcsub($totalIn, $totalOut, 2) : null,
                'student_collections' => $includeCollections ? (string) ($payments->total ?? '0.00') : null,
                'student_collections_count' => $includeCollections ? (int) ($payments->cnt ?? 0) : null,
                'student_refunds' => $includeCollections ? (string) ($refunds->total ?? '0.00') : null,
                'student_refunds_count' => $includeCollections ? (int) ($refunds->cnt ?? 0) : null,
                // Per-source Expense/Revenue breakdowns are only meaningful
                // once their FK columns exist (see FinanceSourceType) —
                // left null (rendered as "—") rather than a fabricated
                // zero, which would misleadingly claim "no expenses that
                // day" instead of "not trackable per-record yet".
                'other_income_available' => $revenuesAvailable,
                'expenses_available' => $expensesAvailable,
            ];
        });
    }

    /**
     * Paginated, audit-friendly drilldown over raw CashTransaction rows.
     * Read-only: no mutation action is exposed from anywhere that consumes
     * this. N+1-safe: forward relations are eager-loaded; the one relation
     * with no forward FK (a refund's link back from payment_refofunds) is
     * batch-resolved in a single extra query for the whole page, not once
     * per row.
     *
     * @param  array{from?:string,to?:string,cash_account_id?:int,type?:string,payment_method?:string,source_type?:string}  $filters
     */
    public function transactionsDrilldown(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $page = $this->cashTransactionQuery($filters)
            ->with(['account', 'creator', 'cashSession', 'invoicePayment.invoice.student', 'payroll'])
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $refundCashTransactionIds = $page->getCollection()
            ->filter(fn (CashTransaction $t) => $t->category === CashTransaction::CATEGORY_REFUND)
            ->pluck('id');

        $refundsByTransactionId = $refundCashTransactionIds->isEmpty()
            ? collect()
            : PaymentRefund::query()->whereIn('cash_transaction_id', $refundCashTransactionIds)->get()->keyBy('cash_transaction_id');

        // Hoisted for the same reason as dailyFinance()'s $revenuesAvailable
        // — resolve() would otherwise repeat a Schema::hasColumn() query
        // per row on this page.
        $revenuesAvailable = FinanceSourceType::isRevenueSourceIntegrated();

        $page->getCollection()->each(function (CashTransaction $transaction) use ($refundsByTransactionId, $revenuesAvailable) {
            $transaction->setAttribute('reporting_source_type', FinanceSourceType::resolve($transaction, $revenuesAvailable));
            $transaction->setAttribute('reporting_refund', $refundsByTransactionId->get($transaction->id));
        });

        return $page;
    }

    /**
     * Every predicate is qualified with the cash_transactions table prefix
     * — callers (e.g. cashMovementSummary()'s byAccount aggregate) join
     * cash_accounts on top of this query, and cash_accounts has its own
     * created_at column; an unqualified whereDate('created_at', ...) would
     * become an ambiguous-column SQL error the moment that join is added.
     */
    private function cashTransactionQuery(array $filters): Builder
    {
        $query = CashTransaction::query();

        if (! empty($filters['from'])) {
            $query->whereDate('cash_transactions.created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('cash_transactions.created_at', '<=', $filters['to']);
        }
        if (! empty($filters['cash_account_id'])) {
            $query->where('cash_transactions.cash_account_id', $filters['cash_account_id']);
        }
        if (! empty($filters['type'])) {
            $query->where('cash_transactions.type', $filters['type']);
        }
        if (! empty($filters['payment_method'])) {
            $query->where('cash_transactions.payment_method', $filters['payment_method']);
        }
        if (! empty($filters['source_type'])) {
            $query->whereRaw(FinanceSourceType::sqlExpression().' = ?', [$filters['source_type']]);
        }

        return $query;
    }

    /**
     * Closing balance as of the end of $date, anchored on the live,
     * authoritative CashAccount.balance rather than assuming any account
     * started at zero — CashAccount can be created with a non-zero opening
     * balance with no backing CashTransaction (see
     * CashAccountController::store()), so summing transaction history from
     * "the beginning of time" would silently misreport for such accounts.
     *
     * closing_at($date) = current stored balance - net movement strictly
     * AFTER $date. Since CashTransaction's own model hooks keep
     * CashAccount.balance live-accurate at all times regardless of when a
     * transaction is dated, "undoing" every movement that happened after
     * the requested date recovers the true balance as of that date, with
     * no assumption about the account's starting point.
     */
    private function closingBalanceAt(?int $cashAccountId, string $date): string
    {
        $currentBalance = $this->currentBalanceScope($cashAccountId);
        $movementAfter = $this->netAmount(
            $this->accountScopedTransactionQuery($cashAccountId)->whereDate('cash_transactions.created_at', '>', $date)
        );

        return bcsub($currentBalance, $movementAfter, 2);
    }

    /**
     * Net (IN - OUT) movement between $from and $to inclusive, scoped only
     * by account — deliberately ignores type/payment_method/source_type
     * drilldown filters, since opening/closing balance is a property of
     * the account itself, not of a filtered subset of its transactions.
     */
    private function netMovementBetween(?int $cashAccountId, string $from, string $to): string
    {
        return $this->netAmount(
            $this->accountScopedTransactionQuery($cashAccountId)
                ->whereDate('cash_transactions.created_at', '>=', $from)
                ->whereDate('cash_transactions.created_at', '<=', $to)
        );
    }

    /** Sum of CashAccount.balance — the live, authoritative figure — scoped to one account if given, else every account. */
    private function currentBalanceScope(?int $cashAccountId): string
    {
        $query = CashAccount::query();
        if ($cashAccountId) {
            $query->where('id', $cashAccountId);
        }

        return (string) $query->sum('balance');
    }

    private function accountScopedTransactionQuery(?int $cashAccountId): Builder
    {
        $query = CashTransaction::query();
        if ($cashAccountId) {
            $query->where('cash_transactions.cash_account_id', $cashAccountId);
        }

        return $query;
    }

    private function netAmount(Builder $query): string
    {
        $in = (string) (clone $query)->where('type', CashTransaction::TYPE_IN)->sum('amount');
        $out = (string) (clone $query)->where('type', CashTransaction::TYPE_OUT)->sum('amount');

        return bcsub($in, $out, 2);
    }
}
