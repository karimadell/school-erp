<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical Expense workflow: draft -> approved -> paid, with void reachable
 * only from draft/approved. Mirrors EmployeePayrollService's row-locked
 * status-transition pattern and InvoiceCancellationService's "never mutate
 * financial history" rule.
 *
 * postToLedger() is the single place a CashTransaction is ever created for
 * an expense — both the explicit pay() transition and Expense's own model
 * hook (which preserves the legacy "Expense::create() with no status =
 * instantly paid" behaviour) end up here. It is idempotent: a row lock on
 * the expense during the check, an existence check against
 * cash_transactions.expense_id before inserting, and that column's own
 * unique constraint as the database-level backstop against a concurrent
 * duplicate insert.
 *
 * create() is the canonical, atomic entry point for making a new Expense.
 * A plain Eloquent Expense::create() is not itself wrapped in a database
 * transaction, and this project's Filament panel does not enable
 * page-level databaseTransactions() either — so for a default/immediately
 * "paid" Expense, an unexpected failure inside the model's own
 * created-hook-triggered postToLedger() call could otherwise leave a
 * committed, paid Expense with no CashTransaction. create() closes that
 * gap by opening its own DB::transaction() around Expense::create(): the
 * insert still fires the existing Expense::booted() hooks exactly as
 * before (no new hook, no second posting call, no recursion) — but now a
 * failure anywhere in that chain rolls the insert back too, instead of
 * leaving an orphaned paid row. See the class docblock's second paragraph
 * for why the hook itself remains (backward compatibility for raw,
 * non-service Expense::create() calls, which stay non-atomic by design —
 * see docs on Expense::booted()).
 */
class ExpenseService
{
    public function __construct(private CashSessionService $sessions) {}

    public function create(array $data, ?User $actor = null): Expense
    {
        if ($actor && empty($data['created_by'])) {
            $data['created_by'] = $actor->id;
        }

        return DB::transaction(fn () => Expense::create($data));
    }

    public function approve(Expense $expense, User $actor): Expense
    {
        abort_unless($actor->can('approve expenses'), 403);

        return DB::transaction(function () use ($expense, $actor): Expense {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if (in_array($expense->status, [Expense::STATUS_APPROVED, Expense::STATUS_PAID], true)) {
                return $expense;
            }
            if ($expense->status !== Expense::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Только черновик можно утвердить.']);
            }

            $expense->forceFill([
                'status' => Expense::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ])->save();

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'expense_approved',
                'model' => 'Expense',
                'model_id' => $expense->id,
                'new_values' => ['status' => $expense->status],
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $expense->fresh();
        });
    }

    public function pay(Expense $expense, User $actor): Expense
    {
        abort_unless($actor->can('post expenses'), 403);

        return DB::transaction(function () use ($expense, $actor): Expense {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->status === Expense::STATUS_PAID) {
                return $expense->fresh('cashTransaction');
            }
            if ($expense->status !== Expense::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => 'Сначала утвердите расход.']);
            }
            if (! $expense->cash_account_id) {
                throw ValidationException::withMessages(['cash_account_id' => 'Укажите кассу или счёт для оплаты.']);
            }

            // Triggers Expense::booted()'s updated hook, which calls
            // postToLedger() below — the actual posting happens there, not
            // here, so the legacy immediate-create path and this explicit
            // transition share exactly one posting code path.
            $expense->forceFill([
                'status' => Expense::STATUS_PAID,
                'paid_by' => $actor->id,
            ])->save();

            return $expense->fresh('cashTransaction');
        });
    }

    public function void(Expense $expense, User $actor, string $reason): Expense
    {
        abort_unless($actor->can('void expenses'), 403);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['void_reason' => 'Укажите причину отмены.']);
        }

        return DB::transaction(function () use ($expense, $actor, $reason): Expense {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->status === Expense::STATUS_VOID) {
                return $expense;
            }
            if ($expense->status === Expense::STATUS_PAID) {
                throw ValidationException::withMessages([
                    'status' => 'Оплаченный расход нельзя отменить. Оформите корректирующую запись отдельной операцией.',
                ]);
            }

            $expense->forceFill([
                'status' => Expense::STATUS_VOID,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'expense_voided',
                'model' => 'Expense',
                'model_id' => $expense->id,
                'new_values' => ['status' => $expense->status, 'reason' => $reason],
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $expense->fresh();
        });
    }

    public function postToLedger(Expense $expense): void
    {
        DB::transaction(function () use ($expense): void {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if (CashTransaction::query()->where('expense_id', $expense->id)->exists()) {
                return;
            }

            $account = CashAccount::query()->lockForUpdate()->find($expense->cash_account_id);
            if (! $account) {
                return;
            }

            $sessionId = null;
            if ($account->isCashDrawer()) {
                $sessionId = $this->sessions->activeFor($account, lock: true)?->id;
            }

            $actorId = $expense->paid_by ?: $expense->created_by ?: auth()->id();

            try {
                $transaction = CashTransaction::create([
                    'cash_account_id' => $account->id,
                    'cash_session_id' => $sessionId,
                    'created_by' => $actorId,
                    'expense_id' => $expense->id,
                    'amount' => $expense->amount,
                    'type' => CashTransaction::TYPE_OUT,
                    'category' => CashTransaction::CATEGORY_EXPENSE,
                    'payment_method' => $expense->payment_method,
                    'description' => 'Расход: '.$expense->title,
                ]);
            } catch (QueryException $exception) {
                if (str_contains($exception->getMessage(), 'UNIQUE') || str_contains($exception->getMessage(), 'Duplicate entry')) {
                    // Lost a race with a concurrent posting attempt for the
                    // same expense — the other request already posted it.
                    return;
                }
                throw $exception;
            }

            if (! $expense->paid_at) {
                $expense->forceFill(['paid_at' => now()])->saveQuietly();
            }

            AuditLog::create([
                'user_id' => $actorId,
                'action' => 'expense_paid',
                'model' => 'Expense',
                'model_id' => $expense->id,
                'new_values' => [
                    'cash_transaction_id' => $transaction->id,
                    'amount' => (string) $expense->amount,
                    'cash_account_id' => $account->id,
                ],
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });
    }
}
