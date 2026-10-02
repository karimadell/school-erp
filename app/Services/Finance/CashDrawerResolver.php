<?php

namespace App\Services\Finance;

use App\Models\CashAccount;
use App\Models\CashSession;
use App\Models\CashTransaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The single authority for which cash drawer (account + open session) may
 * receive a CASH receipt (PR 4 of the frozen Finance corrective plan).
 *
 * An eligible drawer is an ACTIVE, type=cash account whose role is
 * 'operating' or null (an ordinary extra drawer) — never owner cash, bank or
 * Instapay — with exactly one OPEN CashSession, and that session was opened
 * by the CURRENT ACTOR. Another user's open session is never used, whatever
 * else is true (even when it is the only open session in the school).
 *
 * Two layers use it:
 *  - entry points resolve WHICH account (paymentAccountId()/resolve(): 0 →
 *    block, 1 → auto-select, several → the actor must choose one of theirs;
 *    a submitted id is only ever accepted if it is in the actor's own set);
 *  - the posting engines (InvoicePaymentService::record(),
 *    RevenueService::postToLedger()) bind the CashTransaction to the actor's
 *    own session via sessionForReceipt(), inside their transaction and with
 *    a row lock, so a session closed or an account deactivated after the
 *    form was rendered is caught at posting time.
 */
class CashDrawerResolver
{
    public const NO_OWN_SESSION = 'Для приёма наличных нужна открытая кассовая смена — откройте свою смену по кассе.';

    public const SEVERAL_OWN_SESSIONS = 'У вас открыто несколько кассовых смен — выберите кассу, в которую поступили наличные.';

    public const NOT_ELIGIBLE = 'Выбранная касса недоступна для приёма наличных: нужна активная касса с открытой вами кассовой сменой.';

    public const AMBIGUOUS_SESSIONS = 'По этой кассе открыто несколько смен — обратитесь к администратору, операция не проведена.';

    /**
     * The actor's own eligible drawers, each as its open CashSession with
     * account loaded. Read-only and unlocked — for choosing; posting
     * re-validates under lock via sessionForReceipt().
     *
     * @return Collection<int, CashSession>
     */
    public function eligibleSessions(?User $actor): Collection
    {
        if (! $actor) {
            return collect();
        }

        return CashSession::query()
            ->with('account')
            ->where('status', CashSession::STATUS_OPEN)
            ->where('opened_by', $actor->id)
            ->whereHas('account', fn ($query) => $this->eligibleAccountScope($query))
            ->orderBy('cash_account_id')
            ->get();
    }

    /**
     * Picks the drawer for a cash receipt: a submitted id must be one of the
     * actor's own eligible drawers; with none submitted, exactly one is
     * auto-selected and several require an explicit choice. Never first().
     */
    public function resolve(?User $actor, ?int $submittedAccountId, string $field = 'cash_account_id'): CashSession
    {
        $sessions = $this->eligibleSessions($actor);

        if ($submittedAccountId) {
            $chosen = $sessions->firstWhere('cash_account_id', $submittedAccountId);
            if (! $chosen) {
                throw ValidationException::withMessages([$field => $sessions->isEmpty() ? self::NO_OWN_SESSION : self::NOT_ELIGIBLE]);
            }

            return $chosen;
        }

        return match ($sessions->count()) {
            0 => throw ValidationException::withMessages([$field => self::NO_OWN_SESSION]),
            1 => $sessions->first(),
            default => throw ValidationException::withMessages([$field => self::SEVERAL_OWN_SESSIONS]),
        };
    }

    /**
     * The account id a student-payment entry point hands to
     * InvoicePaymentService::record(): for cash, the actor's own resolved
     * drawer; every other method keeps its existing canonical routing
     * (CashAccount::resolvePaymentAccountId()), unchanged. Errors are keyed
     * on 'payment_method', the same key record() has always used for a
     * cash-session failure.
     */
    public function paymentAccountId(string $paymentMethod, ?int $submittedAccountId, ?User $actor, string $field = 'payment_method'): int
    {
        if ($paymentMethod !== CashTransaction::METHOD_CASH) {
            return CashAccount::resolvePaymentAccountId($paymentMethod, $submittedAccountId);
        }

        return $this->resolve($actor, $submittedAccountId, $field)->cash_account_id;
    }

    /**
     * Posting-time binding, called by an engine inside its own transaction
     * with $account already locked: the account must be an eligible drawer
     * and its single open session must have been opened by $actor. The
     * session row is locked so it cannot be closed under the posting.
     */
    public function sessionForReceipt(CashAccount $account, ?User $actor, string $field = 'cash_account_id'): CashSession
    {
        if (! $actor || ! $this->isEligibleAccount($account)) {
            throw ValidationException::withMessages([$field => $actor ? self::NOT_ELIGIBLE : self::NO_OWN_SESSION]);
        }

        $open = CashSession::query()
            ->where('cash_account_id', $account->id)
            ->where('status', CashSession::STATUS_OPEN)
            ->lockForUpdate()
            ->get();

        // CashSessionService::open() keeps one open session per account, but
        // that is an application rule, not a database constraint — never pick
        // one of several.
        if ($open->count() > 1) {
            throw ValidationException::withMessages([$field => self::AMBIGUOUS_SESSIONS]);
        }

        $session = $open->first();
        if (! $session || (int) $session->opened_by !== (int) $actor->id) {
            throw ValidationException::withMessages([$field => self::NO_OWN_SESSION]);
        }

        return $session;
    }

    public function isEligibleAccount(CashAccount $account): bool
    {
        return $account->is_active
            && $account->type === CashAccount::TYPE_CASH
            && in_array($account->role, [null, CashAccount::ROLE_OPERATING], true);
    }

    private function eligibleAccountScope($query)
    {
        return $query->where('is_active', true)
            ->where('type', CashAccount::TYPE_CASH)
            ->where(fn ($q) => $q->whereNull('role')->orWhere('role', CashAccount::ROLE_OPERATING));
    }
}
