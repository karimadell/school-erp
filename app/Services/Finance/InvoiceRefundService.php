<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\InvoicePayment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Canonical, auditable refund of a recorded payment.
 *
 * A refund NEVER mutates or deletes the original payment and NEVER writes a
 * negative InvoicePayment (the disabled legacy behaviour). It creates an
 * immutable PaymentRefund plus a single outgoing CashTransaction, atomically,
 * and recomputes the invoice/installment balances so outstanding debt rises
 * again by exactly the refunded amount.
 */
class InvoiceRefundService
{
    public function refund(
        int $invoicePaymentId,
        string $amount,
        string $reason,
        string $idempotencyKey,
        ?User $actor = null,
        ?int $cashAccountId = null,
    ): PaymentRefund {
        $amount = $this->money($amount);
        $reason = trim($reason);

        if (! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Укажите корректный ключ повторного запроса.']);
        }
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Укажите причину возврата.']);
        }
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма возврата должна быть больше нуля.']);
        }

        return DB::transaction(function () use ($invoicePaymentId, $amount, $reason, $idempotencyKey, $actor, $cashAccountId) {
            $hash = hash('sha256', implode('|', [$invoicePaymentId, $amount, $cashAccountId ?? '']));

            $existing = PaymentRefund::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->replay($existing, $hash);
            }

            $payment = InvoicePayment::query()->lockForUpdate()->find($invoicePaymentId);
            if (! $payment) {
                throw ValidationException::withMessages(['invoice_payment_id' => 'Платёж не найден.']);
            }

            // Re-check under the payment lock so concurrent retries serialise.
            $existing = PaymentRefund::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->replay($existing, $hash);
            }

            $invoice = $payment->invoice()->lockForUpdate()->first();
            if (! $invoice) {
                throw ValidationException::withMessages(['invoice_id' => 'Счёт не найден.']);
            }

            // A refund reversing the legacy negative-amount rows is never valid.
            if (bccomp((string) $payment->amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['invoice_payment_id' => 'Этот платёж нельзя вернуть.']);
            }

            // 1) The specific payment cannot be over-refunded.
            $perPaymentRemaining = bcsub((string) $payment->amount, $payment->refundedAmount(), 2);

            // 2) The invoice as a whole cannot refund below its protected,
            //    non-refundable portion (e.g. registration fee).
            $grossPaid = bcadd((string) $invoice->payments()->reorder()->sum('amount'), '0', 2);
            $nonRefundable = bcadd((string) $invoice->items()->where('is_non_refundable', true)->sum('amount'), '0', 2);
            $nonRefundableEffective = bccomp($nonRefundable, $grossPaid, 2) > 0 ? $grossPaid : $nonRefundable;
            $invoiceRefundableRemaining = bcsub(bcsub($grossPaid, $nonRefundableEffective, 2), $invoice->refundedAmount(), 2);
            if (bccomp($invoiceRefundableRemaining, '0.00', 2) < 0) {
                $invoiceRefundableRemaining = '0.00';
            }

            $allowed = bccomp($perPaymentRemaining, $invoiceRefundableRemaining, 2) <= 0
                ? $perPaymentRemaining
                : $invoiceRefundableRemaining;

            if (bccomp($amount, $allowed, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Сумма возврата превышает доступную к возврату сумму.',
                ]);
            }

            $accountId = $cashAccountId ?? $payment->cash_account_id;
            if ($accountId === null) {
                throw ValidationException::withMessages(['cash_account_id' => 'Не указана касса для возврата.']);
            }
            $account = CashAccount::query()->lockForUpdate()->find($accountId);
            if (! $account) {
                throw ValidationException::withMessages(['cash_account_id' => 'Касса не найдена.']);
            }
            if (! $account->is_active) {
                throw ValidationException::withMessages(['cash_account_id' => 'Выбранная касса неактивна.']);
            }

            // Phase 3 — a cash refund is a physical cash outflow from a drawer,
            // so it requires an open shift, exactly like a cash collection. No
            // orphan cash transaction: resolve (and lock) the active session
            // now, before anything is written; if there is none, reject and let
            // the surrounding transaction roll the whole refund back atomically
            // (no refund record, no cash movement, no payment mutation).
            // Non-cash accounts have no physical drawer and stay exempt.
            $cashSessionId = null;
            if ($account->isCashDrawer()) {
                $session = app(CashSessionService::class)->activeFor($account, lock: true);
                if (! $session) {
                    throw ValidationException::withMessages([
                        'cash_account_id' => 'Для возврата наличными нужна открытая кассовая смена.',
                    ]);
                }
                $cashSessionId = $session->id;
            }

            $refund = PaymentRefund::create([
                'invoice_payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'student_id' => $invoice->student_id,
                'invoice_installment_id' => $payment->invoice_installment_id,
                'cash_account_id' => $account->id,
                'amount' => $amount,
                'currency' => 'EGP',
                'reason' => $reason,
                'refunded_at' => now(),
                'created_by' => $actor?->id,
                'idempotency_key' => $idempotencyKey,
                'idempotency_hash' => $hash,
            ]);
            $refund->refund_number = PaymentRefund::numberFor($refund->id, $refund->created_at->format('Y'));
            $refund->save();

            // Outgoing money movement — one CashTransaction, its own booted hook
            // decrements the account balance exactly once.
            // The refund's cash movement stands alone (cash_transactions has a
            // unique invoice_payment_id already used by the income row). The
            // link back is PaymentRefund->cash_transaction_id, set below.
            // Its cash_session_id was resolved (and its session locked) above:
            // an id for a cash drawer, null for a non-cash account.
            $transaction = CashTransaction::create([
                'cash_account_id' => $account->id,
                'cash_session_id' => $cashSessionId,
                'created_by' => $actor?->id,
                'amount' => $amount,
                'type' => CashTransaction::TYPE_OUT,
                'category' => CashTransaction::CATEGORY_REFUND,
                'description' => "Возврат {$refund->refund_number} по платежу {$payment->payment_number}",
            ]);
            $refund->forceFill(['cash_transaction_id' => $transaction->id])->save();

            // Recompute balances (net of refunds) — outstanding rises again.
            $invoice->refreshPaymentStatus();
            $payment->installment?->refreshStatus();

            AuditLog::create([
                'user_id' => $actor?->id,
                'action' => 'payment_refunded',
                'model' => 'PaymentRefund',
                'model_id' => $refund->id,
                'new_values' => [
                    'refund_number' => $refund->refund_number,
                    'invoice_payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $amount,
                    'reason' => $reason,
                ],
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $refund->fresh(['originalPayment', 'invoice', 'cashTransaction', 'executor']);
        });
    }

    private function replay(PaymentRefund $refund, string $hash): PaymentRefund
    {
        if (! hash_equals((string) $refund->idempotency_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ повторного запроса уже использован для другого возврата.']);
        }

        return $refund;
    }

    private function money(string $value): string
    {
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Укажите корректную сумму возврата.']);
        }

        return bcadd($value, '0', 2);
    }
}
