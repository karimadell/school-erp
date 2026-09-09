<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payee;
use App\Services\Finance\ExpenseService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExpenseWorkflowTest extends FinanceOperationsTestCase
{
    private function draftExpense(array $overrides = []): Expense
    {
        return Expense::create(array_merge([
            'title' => 'Канцелярия',
            'amount' => '300.00',
            'category' => 'supplies',
            'expense_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'status' => Expense::STATUS_DRAFT,
        ], $overrides));
    }

    public function test_draft_expense_has_no_ledger_impact(): void
    {
        $before = (string) $this->cash->fresh()->balance;

        $expense = $this->draftExpense();

        $this->assertTrue($expense->isDraft());
        $this->assertDatabaseMissing('cash_transactions', ['expense_id' => $expense->id]);
        $this->assertSame($before, (string) $this->cash->fresh()->balance);
    }

    public function test_approve_has_no_ledger_impact(): void
    {
        $before = (string) $this->cash->fresh()->balance;
        $expense = $this->draftExpense();

        $approved = app(ExpenseService::class)->approve($expense, $this->accountant);

        $this->assertTrue($approved->isApproved());
        $this->assertNotNull($approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertDatabaseMissing('cash_transactions', ['expense_id' => $expense->id]);
        $this->assertSame($before, (string) $this->cash->fresh()->balance);
    }

    public function test_pay_posts_exactly_once(): void
    {
        $before = (string) $this->cash->fresh()->balance;
        $expense = $this->draftExpense();
        app(ExpenseService::class)->approve($expense, $this->accountant);

        $paid = app(ExpenseService::class)->pay($expense, $this->accountant);

        $this->assertTrue($paid->isPaid());
        $this->assertNotNull($paid->paid_by);
        $this->assertNotNull($paid->paid_at);
        $this->assertSame(1, CashTransaction::query()->where('expense_id', $expense->id)->count());
        $this->assertSame(
            bcsub($before, '300.00', 2),
            (string) $this->cash->fresh()->balance
        );
    }

    public function test_default_legacy_creation_posts_exactly_once(): void
    {
        $before = (string) $this->cash->fresh()->balance;

        // No status specified — the shape every pre-existing caller uses.
        $expense = Expense::create([
            'title' => 'Вода',
            'amount' => '150.00',
            'category' => 'utilities',
            'expense_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
        ]);

        $this->assertSame(Expense::STATUS_PAID, $expense->status);
        $this->assertSame(1, CashTransaction::query()->where('expense_id', $expense->id)->count());
        $this->assertSame(bcsub($before, '150.00', 2), (string) $this->cash->fresh()->balance);
        $this->assertNotNull($expense->fresh()->paid_at);
    }

    public function test_duplicate_posting_is_prevented_at_service_level(): void
    {
        $expense = Expense::create([
            'title' => 'Интернет', 'amount' => '80.00', 'category' => 'internet',
            'expense_date' => today()->toDateString(), 'cash_account_id' => $this->cash->id,
        ]);

        // Simulate a repeated/retried posting attempt for the same expense.
        app(ExpenseService::class)->postToLedger($expense->fresh());
        app(ExpenseService::class)->postToLedger($expense->fresh());
        app(ExpenseService::class)->postToLedger($expense->fresh());

        $this->assertSame(1, CashTransaction::query()->where('expense_id', $expense->id)->count());
    }

    public function test_duplicate_posting_is_prevented_at_database_level(): void
    {
        $expense = Expense::create([
            'title' => 'Ремонт', 'amount' => '500.00', 'category' => 'maintenance',
            'expense_date' => today()->toDateString(), 'cash_account_id' => $this->cash->id,
        ]);

        $this->assertSame(1, CashTransaction::query()->where('expense_id', $expense->id)->count());

        // The unique constraint on cash_transactions.expense_id is the
        // database-level backstop — a second row for the same expense must
        // be rejected even if application-level guards were bypassed.
        $this->expectException(QueryException::class);
        CashTransaction::create([
            'cash_account_id' => $this->cash->id,
            'expense_id' => $expense->id,
            'amount' => '500.00',
            'type' => CashTransaction::TYPE_OUT,
            'category' => CashTransaction::CATEGORY_EXPENSE,
            'description' => 'Duplicate attempt',
        ]);
    }

    public function test_cash_session_attribution(): void
    {
        $expense = Expense::create([
            'title' => 'Уборка', 'amount' => '90.00', 'category' => 'cleaning',
            'expense_date' => today()->toDateString(), 'cash_account_id' => $this->cash->id,
        ]);

        $transaction = CashTransaction::query()->where('expense_id', $expense->id)->firstOrFail();
        $this->assertSame($this->cashSession->id, $transaction->cash_session_id);
    }

    public function test_category_relationship(): void
    {
        $category = ExpenseCategory::create(['name' => 'Коммунальные услуги', 'is_active' => true]);
        $expense = $this->draftExpense(['expense_category_id' => $category->id]);

        $this->assertSame($category->id, $expense->fresh()->expenseCategory->id);
        // Legacy free-text category column stays independently readable.
        $this->assertSame('supplies', $expense->fresh()->category);
    }

    public function test_payee_relationship(): void
    {
        $payee = Payee::create(['name' => 'ООО Ромашка', 'is_active' => true]);
        $expense = $this->draftExpense(['payee_id' => $payee->id]);

        $this->assertSame($payee->id, $expense->fresh()->payee->id);
    }

    public function test_approve_requires_approve_expenses_permission(): void
    {
        $expense = $this->draftExpense();
        $reception = $this->user('reception');

        $this->expectException(HttpException::class);
        app(ExpenseService::class)->approve($expense, $reception);
    }

    public function test_pay_requires_post_expenses_permission(): void
    {
        $expense = $this->draftExpense();
        app(ExpenseService::class)->approve($expense, $this->accountant);
        $reception = $this->user('reception');

        $this->expectException(HttpException::class);
        app(ExpenseService::class)->pay($expense, $reception);
    }

    public function test_void_requires_void_expenses_permission(): void
    {
        $expense = $this->draftExpense();
        $reception = $this->user('reception');

        $this->expectException(HttpException::class);
        app(ExpenseService::class)->void($expense, $reception, 'Ошибка ввода');
    }

    public function test_paid_expense_cannot_be_voided(): void
    {
        $expense = Expense::create([
            'title' => 'Топливо', 'amount' => '200.00', 'category' => 'fuel',
            'expense_date' => today()->toDateString(), 'cash_account_id' => $this->cash->id,
        ]);

        $this->expectException(ValidationException::class);
        app(ExpenseService::class)->void($expense, $this->accountant, 'Ошибка');
    }

    public function test_draft_expense_can_be_voided(): void
    {
        $expense = $this->draftExpense();

        $voided = app(ExpenseService::class)->void($expense, $this->accountant, 'Дублирующая запись');

        $this->assertTrue($voided->isVoid());
        $this->assertSame('Дублирующая запись', $voided->void_reason);
        $this->assertNotNull($voided->voided_by);
        $this->assertNotNull($voided->voided_at);
    }

    public function test_approved_expense_can_be_voided(): void
    {
        $expense = $this->draftExpense();
        app(ExpenseService::class)->approve($expense, $this->accountant);

        $voided = app(ExpenseService::class)->void($expense, $this->accountant, 'Отменено руководителем');

        $this->assertTrue($voided->isVoid());
        $this->assertDatabaseMissing('cash_transactions', ['expense_id' => $expense->id]);
    }

    public function test_paid_expense_is_immutable(): void
    {
        $expense = Expense::create([
            'title' => 'Реклама', 'amount' => '400.00', 'category' => 'marketing',
            'expense_date' => today()->toDateString(), 'cash_account_id' => $this->cash->id,
        ]);

        $this->expectException(LogicException::class);
        $expense->update(['title' => 'Изменённое название']);
    }

    public function test_hard_delete_is_prevented(): void
    {
        $expense = $this->draftExpense();

        $this->expectException(LogicException::class);
        $expense->delete();
    }

    public function test_audit_evidence_for_approve_pay_void(): void
    {
        $expense = $this->draftExpense();
        app(ExpenseService::class)->approve($expense, $this->accountant);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'expense_approved', 'model' => 'Expense', 'model_id' => $expense->id,
        ]);

        app(ExpenseService::class)->pay($expense, $this->accountant);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'expense_paid', 'model' => 'Expense', 'model_id' => $expense->id,
        ]);

        $another = $this->draftExpense(['title' => 'Прочее']);
        app(ExpenseService::class)->void($another, $this->accountant, 'Дубликат');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'expense_voided', 'model' => 'Expense', 'model_id' => $another->id,
        ]);
    }

    public function test_private_attachment_metadata_is_derived_from_stored_file(): void
    {
        $disk = config('filesystems.uploads.private');
        Storage::fake($disk);
        Storage::disk($disk)->put('expenses/receipt.pdf', 'fake-pdf-contents');

        $metadata = Expense::attachmentMetadataFrom('expenses/receipt.pdf');

        $this->assertSame('receipt.pdf', $metadata['attachment_name']);
        $this->assertGreaterThan(0, $metadata['attachment_size']);
        $this->assertNotEmpty($metadata['attachment_type']);
    }

    public function test_attachment_metadata_is_empty_for_missing_file(): void
    {
        $this->assertSame([], Expense::attachmentMetadataFrom(null));
        $this->assertSame([], Expense::attachmentMetadataFrom('expenses/does-not-exist.pdf'));
    }

    public function test_status_filter_and_total_summarize_correctly(): void
    {
        $this->draftExpense(['title' => 'A', 'amount' => '100.00']);
        $this->draftExpense(['title' => 'B', 'amount' => '250.00']);
        Expense::create([
            'title' => 'C', 'amount' => '75.00', 'category' => 'other',
            'expense_date' => today()->toDateString(), 'cash_account_id' => $this->cash->id,
        ]);

        $draftTotal = Expense::query()->where('status', Expense::STATUS_DRAFT)->sum('amount');
        $paidTotal = Expense::query()->where('status', Expense::STATUS_PAID)->sum('amount');

        $this->assertSame(350.0, (float) $draftTotal);
        $this->assertSame(75.0, (float) $paidTotal);
    }

    public function test_category_filter_scopes_correctly(): void
    {
        $utilities = ExpenseCategory::create(['name' => 'Коммунальные услуги 2', 'is_active' => true]);
        $supplies = ExpenseCategory::create(['name' => 'Канцтовары 2', 'is_active' => true]);

        $this->draftExpense(['expense_category_id' => $utilities->id]);
        $this->draftExpense(['expense_category_id' => $supplies->id]);
        $this->draftExpense(['expense_category_id' => $supplies->id]);

        $this->assertSame(1, Expense::query()->where('expense_category_id', $utilities->id)->count());
        $this->assertSame(2, Expense::query()->where('expense_category_id', $supplies->id)->count());
    }

    public function test_date_range_filter_scopes_correctly(): void
    {
        $this->draftExpense(['title' => 'Old', 'expense_date' => '2026-01-01']);
        $this->draftExpense(['title' => 'InRange', 'expense_date' => '2026-06-15']);
        $this->draftExpense(['title' => 'Future', 'expense_date' => '2027-01-01']);

        $inRange = Expense::query()
            ->whereDate('expense_date', '>=', '2026-06-01')
            ->whereDate('expense_date', '<=', '2026-06-30')
            ->get();

        $this->assertCount(1, $inRange);
        $this->assertSame('InRange', $inRange->first()->title);
    }
}
