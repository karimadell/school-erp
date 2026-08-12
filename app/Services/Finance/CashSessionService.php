<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 3 — the canonical, auditable lifecycle of a cash-drawer session.
 *
 * Opening derives its expected baseline from the drawer's own history (previous
 * closed session, or the trusted account balance for the first-ever shift) —
 * never an arbitrary manual amount. Closing takes a single counted total,
 * reconciles it against the session's own cash movements, and records any
 * shortage/overage immutably. It NEVER posts a balancing entry or mutates the
 * account balance: reconciliation reports the truth, it does not "correct" it.
 */
class CashSessionService
{
    /**
     * Open a session on a cash-type drawer.
     *
     * @throws ValidationException
     */
    public function open(CashAccount $account, User $actor, ?string $note = null): CashSession
    {
        return DB::transaction(function () use ($account, $actor, $note) {
            // Serialise concurrent opens on the same drawer so two cashiers
            // cannot both slip past the duplicate-active check.
            $account = CashAccount::query()->lockForUpdate()->find($account->id);
            if (! $account) {
                throw ValidationException::withMessages(['cash_account_id' => 'Касса не найдена.']);
            }
            if (! $account->is_active) {
                throw ValidationException::withMessages(['cash_account_id' => 'Выбранная касса неактивна.']);
            }
            if (! $account->isCashDrawer()) {
                throw ValidationException::withMessages(['cash_account_id' => 'Кассовые смены доступны только для наличных касс.']);
            }

            if ($this->activeFor($account, lock: true)) {
                throw ValidationException::withMessages(['cash_account_id' => 'По этой кассе уже открыта смена.']);
            }

            [$opening, $source] = $this->resolveOpeningExpected($account);

            $session = CashSession::create([
                'cash_account_id' => $account->id,
                'opened_by' => $actor->id,
                'opened_at' => now(),
                'opening_expected' => $opening,
                'opening_expected_source' => $source,
                'status' => CashSession::STATUS_OPEN,
                'open_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'cash_session_opened',
                'model' => 'CashSession',
                'model_id' => $session->id,
                'new_values' => [
                    'cash_account_id' => $account->id,
                    'opening_expected' => $opening,
                    'opening_expected_source' => $source,
                ],
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $session;
        });
    }

    /**
     * Close and reconcile an open session against a single counted total.
     *
     * @throws ValidationException
     */
    public function close(CashSession $session, User $actor, string $countedTotal, ?string $note = null): CashSession
    {
        $countedTotal = $this->money($countedTotal);
        $note = $note !== null ? trim($note) : null;

        if (bccomp($countedTotal, '0.00', 2) < 0) {
            throw ValidationException::withMessages(['closing_counted' => 'Фактический остаток не может быть отрицательным.']);
        }

        return DB::transaction(function () use ($session, $actor, $countedTotal, $note) {
            $session = CashSession::query()->lockForUpdate()->find($session->id);
            if (! $session) {
                throw ValidationException::withMessages(['cash_session_id' => 'Кассовая смена не найдена.']);
            }
            if (! $session->isOpen()) {
                throw ValidationException::withMessages(['cash_session_id' => 'Кассовая смена уже закрыта.']);
            }

            $expected = $session->expectedClosing();
            $variance = bcsub($countedTotal, $expected, 2);
            $hasVariance = bccomp($variance, '0.00', 2) !== 0; // tolerance = 0.00

            // A non-zero variance is a higher-risk close: it needs a Russian
            // reason AND a user authorised to accept shortages/overages.
            if ($hasVariance) {
                if (! $actor->can('close cash sessions with variance')) {
                    throw ValidationException::withMessages([
                        'closing_counted' => 'У вас нет прав закрывать смену с расхождением.',
                    ]);
                }
                if ($note === null || $note === '') {
                    throw ValidationException::withMessages([
                        'close_note' => 'Укажите причину расхождения.',
                    ]);
                }
            }

            $session->forceFill([
                'closing_counted' => $countedTotal,
                'expected_cash' => $expected,
                'variance' => $variance,
                'status' => CashSession::STATUS_CLOSED,
                'closed_by' => $actor->id,
                'closed_at' => now(),
                'reconciled_by' => $actor->id,
                'reconciled_at' => now(),
                'close_note' => $note !== '' ? $note : null,
            ])->save();

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'cash_session_closed',
                'model' => 'CashSession',
                'model_id' => $session->id,
                'new_values' => [
                    'expected_cash' => $expected,
                    'closing_counted' => $countedTotal,
                    'variance' => $variance,
                    'close_note' => $session->close_note,
                ],
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $session->fresh(['account', 'opener', 'closer']);
        });
    }

    /**
     * The single open session for a drawer, or null. Optionally locked so the
     * caller serialises against concurrent opens/collections.
     */
    public function activeFor(CashAccount $account, bool $lock = false): ?CashSession
    {
        $query = CashSession::query()
            ->where('cash_account_id', $account->id)
            ->where('status', CashSession::STATUS_OPEN)
            ->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Resolve the expected opening baseline for a new session:
     *  - the previous closed session's counted total (what is physically left
     *    in the drawer), or
     *  - the trusted account balance for the first-ever session.
     *
     * @return array{0: string, 1: string} [amount, source]
     */
    public function resolveOpeningExpected(CashAccount $account): array
    {
        $previous = CashSession::query()
            ->where('cash_account_id', $account->id)
            ->where('status', CashSession::STATUS_CLOSED)
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->first();

        if ($previous && $previous->closing_counted !== null) {
            return [$this->money($previous->closing_counted), CashSession::SOURCE_PREVIOUS_SESSION];
        }

        return [$this->money($account->balance), CashSession::SOURCE_ACCOUNT_BALANCE];
    }

    private function money($value): string
    {
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', (string) $value)) {
            throw ValidationException::withMessages(['closing_counted' => 'Укажите корректную сумму.']);
        }

        return bcadd((string) $value, '0', 2);
    }
}
