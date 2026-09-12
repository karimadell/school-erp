<?php

namespace App\Services\Finance\Reporting;

use App\Models\CashTransaction;
use Illuminate\Support\Facades\Schema;

/**
 * Deterministic mapping of a CashTransaction row to a stable reporting
 * source type, built entirely from columns that exist on THIS base.
 * Expenses V1 and Non-Tuition Revenues V1 are separate, unmerged sibling
 * branches — their cash_transactions.expense_id / revenue_entry_id /
 * reversed_revenue_entry_id columns do not exist here, and this class
 * never references their Expense/RevenueEntry model classes at all, so
 * there is nothing to duplicate.
 *
 * Resolution today rests on cash_transactions' own real columns
 * (invoice_payment_id, teacher_salary_id, category) — confirmed by reading
 * every migration that touches the table, not assumed from $fillable
 * (which lists a dead `invoice_id` no migration ever created).
 *
 * REVENUE INTEGRATION: already implemented, schema-gated. resolve() and
 * sqlExpression() both check revenue_entry_id -> NON_TUITION_REVENUE and
 * reversed_revenue_entry_id -> REVENUE_REVERSAL BEFORE the category
 * fallback, so a reversal (category=refund) is never mistaken for a
 * student refund and an original post (category=income) is never
 * mistaken for generic other_income. isRevenueSourceIntegrated() gates
 * every access to those two columns — in PHP a missing column is a safe
 * null via Eloquent's default (non-strict) attribute access, but the SQL
 * side cannot rely on that (referencing an absent column is a hard query
 * error), so sqlExpression() only ever emits those column names into the
 * generated SQL string once isRevenueSourceIntegrated() confirms they
 * exist. No dependency on the RevenueEntry model or Revenues V1's
 * migrations is introduced by this.
 *
 * EXPENSE INTEGRATION EXTENSION POINT (not yet implemented — expense_id
 * has no category-collision risk today, unlike revenue, since nothing
 * else currently maps to category=expense): once Expenses V1 lands, add a
 * `WHEN expense_id IS NOT NULL THEN 'expense'` clause (checked BEFORE the
 * category-only expense fallback, following the same pattern as the
 * revenue clauses above) to both resolve() and sqlExpression(), include it
 * in sourceFkColumns() when isExpenseSourceIntegrated() is true, and it
 * will automatically participate in the ambiguity check.
 */
class FinanceSourceType
{
    const STUDENT_PAYMENT = 'student_payment';

    const STUDENT_REFUND = 'student_refund';

    const PAYROLL = 'payroll';

    const CASH_TRANSFER = 'cash_transfer';

    const EXPENSE = 'expense';

    const OTHER_INCOME = 'other_income';

    // Not yet reachable on this base (see class docblock) — defined now so
    // the taxonomy/UI labels are stable and forward-compatible, but never
    // produced by resolve()/sqlCase() until their source columns exist.
    const NON_TUITION_REVENUE = 'non_tuition_revenue';

    const REVENUE_REVERSAL = 'revenue_reversal';

    // Multiple source-FK columns set simultaneously on one row — should be
    // structurally impossible given every writer sets at most one, but
    // detected and surfaced explicitly rather than silently resolved to
    // whichever column happened to be checked first.
    const AMBIGUOUS = 'ambiguous';

    const UNKNOWN = 'unknown';

    const LABELS = [
        self::STUDENT_PAYMENT => 'Оплата от ученика',
        self::STUDENT_REFUND => 'Возврат ученику',
        self::PAYROLL => 'Заработная плата',
        self::CASH_TRANSFER => 'Перевод между кассами',
        self::EXPENSE => 'Расход',
        self::OTHER_INCOME => 'Прочий доход',
        self::NON_TUITION_REVENUE => 'Прочий доход (учёт)',
        self::REVENUE_REVERSAL => 'Сторно прочего дохода',
        self::AMBIGUOUS => 'Требует проверки',
        self::UNKNOWN => 'Неизвестно',
    ];

    /**
     * Deterministic, single-pass PHP resolution for one already-loaded
     * CashTransaction row (drilldown display). Must stay in exact
     * lock-step with sqlCase() below — verified by a test asserting both
     * agree for one representative row of every currently-reachable type.
     *
     * Revenue precedence: once Revenues V1's revenue_entry_id /
     * reversed_revenue_entry_id columns exist, they are checked BEFORE the
     * category fallback — a reversal (category=refund) must never be
     * classified as a student refund, and an original revenue post
     * (category=income) must never be classified as generic other_income,
     * merely because they share a category with those unrelated cases.
     * isRevenueSourceIntegrated() guards every access so this stays inert
     * (and crash-free even under a hypothetical future strict-attributes
     * mode) on a base where the columns don't exist.
     *
     * $revenueIntegrated: pass the already-computed
     * isRevenueSourceIntegrated() result when resolving many rows in a
     * loop (e.g. a paginated drilldown) so each call doesn't repeat the
     * Schema::hasColumn() introspection query per row — defaults to
     * computing it fresh for one-off callers.
     */
    public static function resolve(CashTransaction $transaction, ?bool $revenueIntegrated = null): string
    {
        $revenueIntegrated ??= self::isRevenueSourceIntegrated();

        $hasInvoicePayment = $transaction->invoice_payment_id !== null;
        $hasTeacherSalary = $transaction->teacher_salary_id !== null;
        $hasRevenueEntry = $revenueIntegrated && $transaction->revenue_entry_id !== null;
        $hasRevenueReversal = $revenueIntegrated && $transaction->reversed_revenue_entry_id !== null;

        $signalCount = collect([$hasInvoicePayment, $hasTeacherSalary, $hasRevenueEntry, $hasRevenueReversal])
            ->filter()->count();

        if ($signalCount > 1) {
            return self::AMBIGUOUS;
        }
        if ($hasInvoicePayment) {
            return self::STUDENT_PAYMENT;
        }
        if ($hasTeacherSalary) {
            return self::PAYROLL;
        }
        if ($hasRevenueReversal) {
            return self::REVENUE_REVERSAL;
        }
        if ($hasRevenueEntry) {
            return self::NON_TUITION_REVENUE;
        }

        return match ($transaction->category) {
            CashTransaction::CATEGORY_TRANSFER => self::CASH_TRANSFER,
            CashTransaction::CATEGORY_REFUND => self::STUDENT_REFUND,
            CashTransaction::CATEGORY_EXPENSE => self::EXPENSE,
            CashTransaction::CATEGORY_INCOME => self::OTHER_INCOME,
            default => self::UNKNOWN,
        };
    }

    /**
     * The same resolution logic as resolve(), expressed as a portable SQL
     * CASE WHEN expression (plain ANSI SQL — no driver-specific syntax),
     * with no alias — safe to reuse inside both SELECT and WHERE clauses
     * (a column alias isn't reliably usable inside WHERE across drivers).
     *
     * The revenue_entry_id/reversed_revenue_entry_id column names are only
     * ever emitted into the generated SQL when isRevenueSourceIntegrated()
     * confirms they exist — referencing an absent column in raw SQL (unlike
     * PHP attribute access) would be a hard query error, not a safe null.
     */
    public static function sqlExpression(): string
    {
        $revenueIntegrated = self::isRevenueSourceIntegrated();
        $columns = self::sourceFkColumns($revenueIntegrated);

        $revenueClauses = $revenueIntegrated
            ? "\n                WHEN reversed_revenue_entry_id IS NOT NULL THEN '".self::REVENUE_REVERSAL."'".
              "\n                WHEN revenue_entry_id IS NOT NULL THEN '".self::NON_TUITION_REVENUE."'"
            : '';

        return "CASE
                WHEN ".self::ambiguityExpression($columns)." THEN '".self::AMBIGUOUS."'
                WHEN invoice_payment_id IS NOT NULL THEN '".self::STUDENT_PAYMENT."'
                WHEN teacher_salary_id IS NOT NULL THEN '".self::PAYROLL."'{$revenueClauses}
                WHEN category = '".CashTransaction::CATEGORY_TRANSFER."' THEN '".self::CASH_TRANSFER."'
                WHEN category = '".CashTransaction::CATEGORY_REFUND."' THEN '".self::STUDENT_REFUND."'
                WHEN category = '".CashTransaction::CATEGORY_EXPENSE."' THEN '".self::EXPENSE."'
                WHEN category = '".CashTransaction::CATEGORY_INCOME."' THEN '".self::OTHER_INCOME."'
                ELSE '".self::UNKNOWN."'
            END";
    }

    /** @return list<string> */
    private static function sourceFkColumns(bool $revenueIntegrated): array
    {
        $columns = ['invoice_payment_id', 'teacher_salary_id'];
        if ($revenueIntegrated) {
            $columns[] = 'revenue_entry_id';
            $columns[] = 'reversed_revenue_entry_id';
        }

        return $columns;
    }

    /**
     * Portable ANSI "more than one of these columns is set" check, built as
     * an OR of pairwise ANDs rather than boolean arithmetic — PostgreSQL
     * has no boolean-to-integer coercion for `+`, unlike MySQL/SQLite,
     * so a SUM()-of-booleans style check would silently break there.
     *
     * @param  list<string>  $columns
     */
    private static function ambiguityExpression(array $columns): string
    {
        $pairs = [];
        foreach ($columns as $i => $left) {
            foreach ($columns as $j => $right) {
                if ($j <= $i) {
                    continue;
                }
                $pairs[] = "({$left} IS NOT NULL AND {$right} IS NOT NULL)";
            }
        }

        return '('.implode(' OR ', $pairs).')';
    }

    /** sqlExpression() aliased as `source_type`, for SELECT/GROUP BY. */
    public static function sqlCase(): string
    {
        return self::sqlExpression().' as source_type';
    }

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    /**
     * Source types that can actually be produced on the CURRENT schema —
     * for filter dropdowns. Deliberately narrower than LABELS: never
     * offers a filter option that can structurally never match anything.
     *
     * @return array<string, string> value => label
     */
    public static function availableTypes(): array
    {
        $types = [
            self::STUDENT_PAYMENT, self::STUDENT_REFUND, self::PAYROLL,
            self::CASH_TRANSFER, self::EXPENSE, self::OTHER_INCOME,
        ];

        if (self::isRevenueSourceIntegrated()) {
            $types[] = self::NON_TUITION_REVENUE;
            $types[] = self::REVENUE_REVERSAL;
        }

        return collect($types)->mapWithKeys(fn (string $type) => [$type => self::label($type)])->all();
    }

    /**
     * Whether Expenses V1's fine-grained link (cash_transactions.expense_id)
     * has been integrated yet. Checked via the column's existence, not the
     * Expense model class — this branch has no dependency on that class.
     */
    public static function isExpenseSourceIntegrated(): bool
    {
        return Schema::hasColumn('cash_transactions', 'expense_id');
    }

    /**
     * Whether Non-Tuition Revenues V1's fine-grained links
     * (revenue_entry_id / reversed_revenue_entry_id) have been integrated.
     */
    public static function isRevenueSourceIntegrated(): bool
    {
        return Schema::hasColumn('cash_transactions', 'revenue_entry_id')
            && Schema::hasColumn('cash_transactions', 'reversed_revenue_entry_id');
    }
}
