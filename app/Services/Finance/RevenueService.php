<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\RevenueEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical, atomic owner of every RevenueEntry state transition and every
 * write to the cash ledger on its behalf: draft -> posted -> reversed.
 *
 * Unlike ExpenseService (which had to retrofit atomicity around a
 * pre-existing model hook for backward compatibility), RevenueEntry has no
 * legacy caller — this service is the ONLY way a RevenueEntry is ever
 * created or transitioned, and there is no model-event auto-posting at
 * all. Every method here opens (or is already inside) one
 * DB::transaction() spanning both the RevenueEntry write and its
 * CashTransaction, so a failure anywhere rolls back everything: no
 * committed posted/reversed RevenueEntry can ever exist without its
 * required ledger row, and vice versa.
 *
 * Idempotency: postToLedger()/reverse() each row-lock the RevenueEntry,
 * check for an already-existing ledger row before inserting, and rely on
 * cash_transactions' own unique constraints (revenue_entry_id,
 * reversed_revenue_entry_id — see the migration that added them) as the
 * database-level backstop against a concurrent duplicate.
 */
class RevenueService
{
    public function __construct(private CashSessionService $sessions) {}

    public function create(array $data, User $actor): RevenueEntry
    {
        // Unconditional — this is the canonical creation entry point and
        // must not rely on the caller (Filament's Policy-gated page) having
        // already checked. Applies to both a plain draft and a
        // create-and-post in one step.
        abort_unless($actor->can('manage revenues'), 403);

        $status = $data['status'] ?? RevenueEntry::STATUS_DRAFT;
        if (! in_array($status, [RevenueEntry::STATUS_DRAFT, RevenueEntry::STATUS_POSTED], true)) {
            throw ValidationException::withMessages(['status' => 'Недопустимый статус для создания записи о доходе.']);
        }
        if ($status === RevenueEntry::STATUS_POSTED) {
            abort_unless($actor->can('post revenues'), 403);
        }

        $this->assertCoreFieldsPresent($data);

        if (empty($data['created_by'])) {
            $data['created_by'] = $actor->id;
        }

        return DB::transaction(function () use ($data, $status, $actor): RevenueEntry {
            $insertData = $data;
            $insertData['status'] = RevenueEntry::STATUS_DRAFT;

            $entry = RevenueEntry::create($insertData);
            if ($entry->reference_number === null) {
                $entry->reference_number = RevenueEntry::numberFor($entry->id, $entry->created_at->format('Y'));
                $entry->saveQuietly();
            }

            if ($status === RevenueEntry::STATUS_POSTED) {
                $entry = $this->postToLedger($entry, $actor);
            }

            return $entry->fresh();
        });
    }

    public function post(RevenueEntry $entry, User $actor): RevenueEntry
    {
        abort_unless($actor->can('post revenues'), 403);

        return DB::transaction(function () use ($entry, $actor): RevenueEntry {
            $entry = RevenueEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->status === RevenueEntry::STATUS_POSTED) {
                return $entry->fresh('cashTransaction');
            }
            if ($entry->status !== RevenueEntry::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Провести можно только черновик.']);
            }

            return $this->postToLedger($entry, $actor)->fresh('cashTransaction');
        });
    }

    /**
     * Canonical deletion path for an unused draft. The model's own
     * deleting() guard still enforces the state invariant (only a draft
     * may ever be deleted) as defense in depth, but authorization and the
     * audit trail live here, not in the model — a raw $entry->delete()
     * satisfies the invariant but carries no permission check and (since
     * this method's audit write happens only here) no audit trail either,
     * which is why Filament's DeleteAction is routed through this method
     * rather than calling delete() directly.
     */
    public function deleteDraft(RevenueEntry $entry, User $actor): void
    {
        abort_unless($actor->can('manage revenues'), 403);

        DB::transaction(function () use ($entry, $actor): void {
            $entry = RevenueEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->status !== RevenueEntry::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Удалить можно только черновик.']);
            }
            if (CashTransaction::query()->where('revenue_entry_id', $entry->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'Нельзя удалить запись, по которой уже есть кассовая операция.']);
            }

            $snapshot = [
                'reference_number' => $entry->reference_number,
                'amount' => (string) $entry->amount,
                'revenue_category_id' => $entry->revenue_category_id,
            ];
            $entryId = $entry->id;

            $entry->delete();

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'revenue_draft_deleted',
                'model' => 'RevenueEntry',
                'model_id' => $entryId,
                'old_values' => $snapshot,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });
    }

    public function reverse(RevenueEntry $entry, User $actor, string $reason): RevenueEntry
    {
        abort_unless($actor->can('reverse revenues'), 403);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reversal_reason' => 'Укажите причину сторно.']);
        }

        return DB::transaction(function () use ($entry, $actor, $reason): RevenueEntry {
            $entry = RevenueEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->status === RevenueEntry::STATUS_REVERSED) {
                return $entry->fresh('reversalTransaction');
            }
            if ($entry->status !== RevenueEntry::STATUS_POSTED) {
                throw ValidationException::withMessages(['status' => 'Сторнировать можно только проведённый доход.']);
            }

            if (CashTransaction::query()->where('reversed_revenue_entry_id', $entry->id)->exists()) {
                return $entry->fresh('reversalTransaction');
            }

            $originalTransaction = CashTransaction::query()->where('revenue_entry_id', $entry->id)->first();
            if (! $originalTransaction) {
                throw ValidationException::withMessages(['status' => 'Не найдена исходная кассовая операция для сторно.']);
            }

            $account = CashAccount::query()->lockForUpdate()->findOrFail($originalTransaction->cash_account_id);

            $sessionId = null;
            if ($account->isCashDrawer()) {
                $session = $this->sessions->activeFor($account, lock: true);
                if (! $session) {
                    throw ValidationException::withMessages([
                        'cash_account_id' => 'Для сторно по этой кассе нужна открытая кассовая смена.',
                    ]);
                }
                $sessionId = $session->id;
            }

            try {
                $reversal = CashTransaction::create([
                    'cash_account_id' => $account->id,
                    'cash_session_id' => $sessionId,
                    'created_by' => $actor->id,
                    'reversed_revenue_entry_id' => $entry->id,
                    'amount' => $originalTransaction->amount,
                    'type' => CashTransaction::TYPE_OUT,
                    'category' => CashTransaction::CATEGORY_REFUND,
                    'description' => "Сторно дохода {$entry->reference_number}: {$reason}",
                ]);
            } catch (QueryException $exception) {
                if (str_contains($exception->getMessage(), 'UNIQUE') || str_contains($exception->getMessage(), 'Duplicate entry')) {
                    return $entry->fresh('reversalTransaction');
                }
                throw $exception;
            }

            $entry->forceFill([
                'status' => RevenueEntry::STATUS_REVERSED,
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ])->save();

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'revenue_reversed',
                'model' => 'RevenueEntry',
                'model_id' => $entry->id,
                'new_values' => [
                    'reversal_cash_transaction_id' => $reversal->id,
                    'amount' => (string) $originalTransaction->amount,
                    'cash_account_id' => $account->id,
                    'reason' => $reason,
                ],
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $entry->fresh('reversalTransaction');
        });
    }

    private function postToLedger(RevenueEntry $entry, ?User $actor): RevenueEntry
    {
        if (CashTransaction::query()->where('revenue_entry_id', $entry->id)->exists()) {
            return $entry->fresh('cashTransaction');
        }

        if (! $entry->revenue_category_id) {
            throw ValidationException::withMessages(['revenue_category_id' => 'Укажите категорию дохода.']);
        }
        if (! $entry->category?->is_active) {
            throw ValidationException::withMessages(['revenue_category_id' => 'Эта категория дохода неактивна.']);
        }
        if (bccomp((string) $entry->amount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма дохода должна быть больше нуля.']);
        }
        if (! $entry->revenue_date) {
            throw ValidationException::withMessages(['revenue_date' => 'Укажите дату дохода.']);
        }
        if (! $entry->cash_account_id) {
            throw ValidationException::withMessages(['cash_account_id' => 'Укажите кассу или счёт для проведения дохода.']);
        }
        if (! $entry->payment_method) {
            throw ValidationException::withMessages(['payment_method' => 'Укажите способ оплаты.']);
        }

        $account = CashAccount::query()->lockForUpdate()->find($entry->cash_account_id);
        if (! $account) {
            throw ValidationException::withMessages(['cash_account_id' => 'Касса или счёт не найдены.']);
        }
        if (! $account->is_active) {
            throw ValidationException::withMessages(['cash_account_id' => 'Выбранная касса/счёт неактивен(на).']);
        }

        $sessionId = null;
        if ($account->isCashDrawer()) {
            $session = $this->sessions->activeFor($account, lock: true);
            if (! $session) {
                throw ValidationException::withMessages([
                    'cash_account_id' => 'Для проведения по этой кассе нужна открытая кассовая смена.',
                ]);
            }
            $sessionId = $session->id;
        }

        $actorId = $actor?->id ?: $entry->created_by;

        try {
            $transaction = CashTransaction::create([
                'cash_account_id' => $account->id,
                'cash_session_id' => $sessionId,
                'created_by' => $actorId,
                'revenue_entry_id' => $entry->id,
                'amount' => $entry->amount,
                'type' => CashTransaction::TYPE_IN,
                'category' => CashTransaction::CATEGORY_INCOME,
                'payment_method' => $entry->payment_method,
                'description' => 'Доход: '.($entry->description ?: ($entry->category?->name_ru ?? $entry->reference_number)),
            ]);
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'UNIQUE') || str_contains($exception->getMessage(), 'Duplicate entry')) {
                return $entry->fresh('cashTransaction');
            }
            throw $exception;
        }

        $entry->forceFill([
            'status' => RevenueEntry::STATUS_POSTED,
            'posted_by' => $actorId,
            'posted_at' => now(),
        ])->save();

        AuditLog::create([
            'user_id' => $actorId,
            'action' => 'revenue_posted',
            'model' => 'RevenueEntry',
            'model_id' => $entry->id,
            'new_values' => [
                'cash_transaction_id' => $transaction->id,
                'amount' => (string) $entry->amount,
                'cash_account_id' => $account->id,
            ],
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return $entry->fresh('cashTransaction');
    }

    private function assertCoreFieldsPresent(array $data): void
    {
        if (empty($data['revenue_category_id'])) {
            throw ValidationException::withMessages(['revenue_category_id' => 'Укажите категорию дохода.']);
        }
        if (! isset($data['amount']) || bccomp((string) $data['amount'], '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма дохода должна быть больше нуля.']);
        }
        if (empty($data['revenue_date'])) {
            throw ValidationException::withMessages(['revenue_date' => 'Укажите дату дохода.']);
        }
    }
}
