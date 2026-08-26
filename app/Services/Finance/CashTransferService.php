<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\CashTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cash Operations Phase 1 — the single canonical account-to-account
 * transfer path. Every internal movement of the school's own money
 * (generic admin transfer, daily handover to the owner, owner topping the
 * operating drawer back up) goes through here and produces exactly one
 * CashTransfer (the audit/reference row) plus exactly two CashTransaction
 * rows (category=transfer, so InvoicePaymentService/EmployeePayrollService
 * income+expense totals and Cash\CashTransactionController's reports
 * already exclude them — see excludeInternalTransfers()). A transfer is
 * never revenue and never an expense.
 *
 * Mirrors InvoicePaymentService's established pattern: row-locked
 * accounts, decimal-string money math (never float), a UUID idempotency
 * key with a payload hash so a replayed submission returns the original
 * transfer instead of moving money twice, and — like every other cash
 * movement in this codebase (InvoicePaymentService, EmployeePayrollService)
 * — any leg that touches a cash-drawer account requires that drawer to
 * have an open CashSession.
 */
class CashTransferService
{
    public function __construct(private CashSessionService $sessions) {}

    public function transfer(
        int $fromAccountId,
        int $toAccountId,
        string $amount,
        string $purpose,
        ?string $notes,
        User $actor,
        string $transferType = CashTransfer::TYPE_INTERNAL,
        ?string $idempotencyKey = null,
        ?string $transferDate = null,
    ): CashTransfer {
        $idempotencyKey = $idempotencyKey ?: (string) Str::uuid();
        if (! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Укажите корректный ключ повторного запроса.']);
        }
        if (! in_array($transferType, [CashTransfer::TYPE_INTERNAL, CashTransfer::TYPE_HANDOVER, CashTransfer::TYPE_OWNER_RETURN], true)) {
            throw ValidationException::withMessages(['transfer_type' => 'Недопустимый тип перевода.']);
        }
        $amount = $this->money($amount);
        $hash = hash('sha256', implode('|', [$fromAccountId, $toAccountId, $amount, $transferType]));

        return DB::transaction(function () use ($fromAccountId, $toAccountId, $amount, $purpose, $notes, $actor, $transferType, $idempotencyKey, $transferDate, $hash) {
            $existing = CashTransfer::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->replay($existing, $hash);
            }

            if ($fromAccountId === $toAccountId) {
                throw ValidationException::withMessages(['to_account_id' => 'Касса-источник и касса-получатель должны отличаться.']);
            }

            $from = CashAccount::query()->lockForUpdate()->find($fromAccountId);
            $to = CashAccount::query()->lockForUpdate()->find($toAccountId);
            if (! $from || ! $to) {
                throw ValidationException::withMessages(['from_account_id' => 'Касса не найдена.']);
            }
            if (! $from->is_active || ! $to->is_active) {
                throw ValidationException::withMessages(['from_account_id' => 'Выбранная касса неактивна.']);
            }

            // Recheck after locking so two concurrent retries of the same
            // request cannot both pass the first, unlocked lookup.
            $existing = CashTransfer::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->replay($existing, $hash);
            }

            if (bccomp($amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Сумма перевода должна быть больше нуля.']);
            }

            if (bccomp((string) $from->balance, $amount, 2) < 0) {
                abort(422, __('cash.insufficient_balance'));
            }

            // Any cash-drawer leg (either direction) requires an open
            // shift on that specific drawer — the same rule
            // InvoicePaymentService applies to incoming cash and
            // EmployeePayrollService applies to outgoing cash. Owner Cash
            // is deliberately a different account type (not a drawer), so
            // handover/owner-return only ever gate on the operating side.
            $fromSessionId = $this->requireOpenSessionIfDrawer($from);
            $toSessionId = $this->requireOpenSessionIfDrawer($to);

            $receiptNumber = 'TR-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(6));

            $transfer = CashTransfer::create([
                'receipt_number' => $receiptNumber,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $amount,
                'notes' => $notes ? $purpose.' - '.$notes : $purpose,
                'transfer_date' => $transferDate ?? now(),
                'created_by' => $actor->id,
                'transfer_type' => $transferType,
                'idempotency_key' => $idempotencyKey,
                'idempotency_hash' => $hash,
            ]);

            // Balance is adjusted exactly once per side, by
            // CashTransaction's own created-event hook — never mutate
            // $from/$to->balance directly here, or the transfer posts twice.
            CashTransaction::create([
                'cash_account_id' => $from->id,
                'cash_session_id' => $fromSessionId,
                'created_by' => $actor->id,
                'amount' => $amount,
                'type' => CashTransaction::TYPE_OUT,
                'category' => CashTransaction::CATEGORY_TRANSFER,
                'description' => "Перевод #{$transfer->receipt_number}: {$purpose} -> {$to->name}",
            ]);

            CashTransaction::create([
                'cash_account_id' => $to->id,
                'cash_session_id' => $toSessionId,
                'created_by' => $actor->id,
                'amount' => $amount,
                'type' => CashTransaction::TYPE_IN,
                'category' => CashTransaction::CATEGORY_TRANSFER,
                'description' => "Перевод #{$transfer->receipt_number}: {$purpose} <- {$from->name}",
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'cash_transfer_'.$transferType,
                'model' => 'CashTransfer',
                'model_id' => $transfer->id,
                'new_values' => [
                    'from_account_id' => $from->id,
                    'to_account_id' => $to->id,
                    'amount' => $amount,
                    'transfer_type' => $transferType,
                ],
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $transfer->fresh(['fromAccount', 'toAccount', 'creator']);
        });
    }

    private function requireOpenSessionIfDrawer(CashAccount $account): ?int
    {
        if (! $account->isCashDrawer()) {
            return null;
        }

        $session = $this->sessions->activeFor($account, lock: true);
        if (! $session) {
            throw ValidationException::withMessages([
                'from_account_id' => "Для операций по кассе «{$account->name}» нужна открытая кассовая смена.",
            ]);
        }

        return $session->id;
    }

    private function replay(CashTransfer $transfer, string $hash): CashTransfer
    {
        if (! hash_equals((string) $transfer->idempotency_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ повторного запроса уже использован для другого перевода.']);
        }

        return $transfer;
    }

    private function money(string $value): string
    {
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Укажите корректную сумму перевода.']);
        }

        return bcadd($value, '0', 2);
    }
}
