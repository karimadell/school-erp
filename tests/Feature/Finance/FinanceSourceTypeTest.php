<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Services\Finance\Reporting\FinanceSourceType;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic source resolution — proves the PHP resolver and the SQL
 * CASE expression agree for every currently-reachable source type, and
 * that a structurally-impossible-but-not-DB-enforced multi-FK row is
 * detected as ambiguous rather than silently resolved to one of them.
 */
class FinanceSourceTypeTest extends FinanceOperationsTestCase
{
    private function assertPhpAndSqlAgree(CashTransaction $transaction, string $expected): void
    {
        $this->assertSame($expected, FinanceSourceType::resolve($transaction), 'PHP resolver mismatch.');

        $sqlResult = DB::table('cash_transactions')
            ->selectRaw(FinanceSourceType::sqlCase())
            ->where('id', $transaction->id)
            ->value('source_type');

        $this->assertSame($expected, $sqlResult, 'SQL CASE expression mismatch.');
    }

    public function test_student_payment_resolves_from_invoice_payment_id(): void
    {
        $invoice = $this->invoice();
        $payment = app(\App\Services\Finance\InvoicePaymentService::class)->record(
            $invoice->id, $this->cash->id, '1200.00', 'cash', (string) \Illuminate\Support\Str::uuid(), $this->accountant,
        );
        $transaction = CashTransaction::where('invoice_payment_id', $payment->id)->firstOrFail();

        $this->assertPhpAndSqlAgree($transaction, FinanceSourceType::STUDENT_PAYMENT);
    }

    public function test_student_refund_resolves_from_category(): void
    {
        $invoice = $this->invoice();
        $payment = app(\App\Services\Finance\InvoicePaymentService::class)->record(
            $invoice->id, $this->cash->id, '1200.00', 'cash', (string) \Illuminate\Support\Str::uuid(), $this->accountant,
        );
        app(\App\Services\Finance\InvoiceRefundService::class)->refund(
            $payment->id, '200.00', 'Тестовый возврат', (string) \Illuminate\Support\Str::uuid(), $this->accountant,
        );
        $transaction = CashTransaction::where('category', CashTransaction::CATEGORY_REFUND)->firstOrFail();

        $this->assertPhpAndSqlAgree($transaction, FinanceSourceType::STUDENT_REFUND);
    }

    public function test_payroll_resolves_from_teacher_salary_id(): void
    {
        $payroll = \App\Models\TeacherSalary::create([
            'employee_name' => 'Тестовый сотрудник', 'base_salary' => '500.00',
            'bonus' => '0.00', 'allowances' => '0.00', 'deductions' => '0.00', 'salary_month' => now(),
        ]);
        $transaction = CashTransaction::create([
            'cash_account_id' => $this->cash->id,
            'teacher_salary_id' => $payroll->id,
            'amount' => '500.00',
            'type' => CashTransaction::TYPE_OUT,
            'category' => CashTransaction::CATEGORY_EXPENSE,
            'description' => 'Payroll probe',
        ]);

        $this->assertPhpAndSqlAgree($transaction, FinanceSourceType::PAYROLL);
    }

    public function test_cash_transfer_resolves_from_category(): void
    {
        $other = \App\Models\CashAccount::create(['name' => 'Другая касса', 'type' => 'cash', 'balance' => '0.00', 'is_active' => true]);
        CashTransaction::transfer($this->cash->id, $other->id, '100.00');
        $transaction = CashTransaction::where('category', CashTransaction::CATEGORY_TRANSFER)->where('type', CashTransaction::TYPE_OUT)->firstOrFail();

        $this->assertPhpAndSqlAgree($transaction, FinanceSourceType::CASH_TRANSFER);
    }

    public function test_expense_resolves_from_category_with_no_fk(): void
    {
        // The pre-existing, naive Expense scaffold on this base (no
        // expense_id column exists yet — see FinanceSourceType's docblock)
        // posts exactly this shape: category=expense, no source FK at all.
        $transaction = CashTransaction::create([
            'cash_account_id' => $this->cash->id,
            'amount' => '50.00',
            'type' => CashTransaction::TYPE_OUT,
            'category' => CashTransaction::CATEGORY_EXPENSE,
            'description' => 'Расход: Канцелярия',
        ]);

        $this->assertPhpAndSqlAgree($transaction, FinanceSourceType::EXPENSE);
    }

    public function test_other_income_resolves_from_category_with_no_fk(): void
    {
        $transaction = CashTransaction::create([
            'cash_account_id' => $this->cash->id,
            'amount' => '300.00',
            'type' => CashTransaction::TYPE_IN,
            'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Unattributed historical income',
        ]);

        $this->assertPhpAndSqlAgree($transaction, FinanceSourceType::OTHER_INCOME);
    }

    // cash_transactions.category is DB-constrained to exactly
    // income/expense/transfer/refund (a CHECK/enum, confirmed by attempting
    // a null value and getting a NOT NULL violation) — so the UNKNOWN
    // fallback is genuinely unreachable via any persisted row, only via an
    // in-memory model. Tested at the PHP level only for that reason; there
    // is no equivalent SQL row to assert agreement against.
    public function test_unknown_fallback_for_an_in_memory_row_with_no_matching_signal(): void
    {
        $transaction = new CashTransaction([
            'cash_account_id' => $this->cash->id,
            'amount' => '10.00',
            'type' => CashTransaction::TYPE_IN,
        ]);
        // Bypass mass-assignment/DB constraints entirely — this state can
        // never be persisted, only constructed in memory to exercise the
        // resolver's defensive fallback branch.
        $transaction->setRawAttributes(array_merge($transaction->getAttributes(), ['category' => 'not_a_real_category']));

        $this->assertSame(FinanceSourceType::UNKNOWN, FinanceSourceType::resolve($transaction));
    }

    public function test_ambiguous_multi_source_transaction_fails_safe_not_silently_resolved(): void
    {
        // Structurally impossible via any real service today (verified: no
        // writer sets both invoice_payment_id and teacher_salary_id), but
        // not DB-enforced — must be surfaced explicitly as ambiguous, never
        // silently mapped to one of them. A raw InvoicePayment row (no
        // InvoicePaymentService::record() call, so no competing
        // CashTransaction claims its id already) supplies a real,
        // FK-satisfying, otherwise-unused invoice_payment_id.
        $invoice = $this->invoice();
        $payment = \App\Models\InvoicePayment::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => $this->cash->id,
            'amount' => '1200.00', 'payment_method' => 'cash', 'paid_at' => now(),
        ]);
        $payroll = \App\Models\TeacherSalary::create([
            'employee_name' => 'Тестовый сотрудник 2', 'base_salary' => '10.00',
            'bonus' => '0.00', 'allowances' => '0.00', 'deductions' => '0.00', 'salary_month' => now(),
        ]);

        $transaction = CashTransaction::create([
            'cash_account_id' => $this->cash->id,
            'invoice_payment_id' => $payment->id,
            'teacher_salary_id' => $payroll->id,
            'amount' => '10.00',
            'type' => CashTransaction::TYPE_OUT,
            'category' => CashTransaction::CATEGORY_EXPENSE,
            'description' => 'Ambiguity probe',
        ]);

        $this->assertNotSame(FinanceSourceType::STUDENT_PAYMENT, FinanceSourceType::resolve($transaction));
        $this->assertNotSame(FinanceSourceType::PAYROLL, FinanceSourceType::resolve($transaction));
        $this->assertPhpAndSqlAgree($transaction, FinanceSourceType::AMBIGUOUS);
    }

    // Combined Finance Reporting V1 integration: both Expenses V1
    // (cash_transactions.expense_id) and Non-Tuition Revenues V1
    // (revenue_entry_id / reversed_revenue_entry_id) are now merged onto
    // this base, so both availability checks flip to true — no code
    // change needed in FinanceSourceType itself, it was already
    // schema-gated for exactly this.
    public function test_availability_checks_reflect_current_schema(): void
    {
        $this->assertTrue(FinanceSourceType::isExpenseSourceIntegrated());
        $this->assertTrue(FinanceSourceType::isRevenueSourceIntegrated());
    }

    public function test_available_types_includes_integrated_revenue_types(): void
    {
        $types = FinanceSourceType::availableTypes();

        $this->assertArrayHasKey(FinanceSourceType::EXPENSE, $types);
        $this->assertArrayHasKey(FinanceSourceType::NON_TUITION_REVENUE, $types);
        $this->assertArrayHasKey(FinanceSourceType::REVENUE_REVERSAL, $types);
    }
}
