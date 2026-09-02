<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\InvoicePayment;
use App\Models\PaymentAllocationCoveragePeriod;
use App\Models\PaymentRefund;
use App\Models\PaymentRefundAllocation;
use App\Models\PaymentRefundAllocationCoveragePeriod;
use App\Models\User;
use Illuminate\Support\Collection;
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
    /**
     * @param  ?array<int, array{payment_allocation_id: int, amount: string}>  $allocations
     *         Finance V2, Phase 1D (docs/finance-v2-architecture.md §19
     *         Phase 1D). Which PaymentAllocation(s) of the original payment
     *         this refund reverses, and how much of each:
     *           - Omitted (null) against a payment with zero
     *             PaymentAllocation rows: legal — historical/unattributed
     *             compatibility. Zero PaymentRefundAllocation rows created.
     *           - Omitted (null) against a payment with exactly one
     *             PaymentAllocation: auto-attributed to it, unless that
     *             allocation's InvoiceItem is non-refundable (rejected) or
     *             the refund would exceed its remaining capacity (rejected).
     *           - Omitted (null) against a payment whose own
     *             PaymentAllocation rows are themselves inconsistent
     *             (neither zero nor summing to the payment's amount — a
     *             state canonical code should never produce): treated as
     *             unattributed, exactly like the zero-allocation case.
     *             Never guessed.
     *           - Omitted (null) against a payment with multiple
     *             PaymentAllocations: auto-attributed only when $amount
     *             exactly exhausts every remaining refundable balance
     *             (the only mathematically forced split); otherwise
     *             rejected — an explicit split is required.
     *           - Supplied explicitly: every payment_allocation_id must
     *             belong to this same payment, reference a refundable
     *             InvoiceItem, be strictly positive, appear at most once,
     *             and the amounts must sum to exactly $amount. Never
     *             inferred, never proportional. Rejected outright when the
     *             payment's own allocation coverage is inconsistent.
     */
    public function refund(
        int $invoicePaymentId,
        string $amount,
        string $reason,
        string $idempotencyKey,
        ?User $actor = null,
        ?int $cashAccountId = null,
        ?array $allocations = null,
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

        return DB::transaction(function () use ($invoicePaymentId, $amount, $reason, $idempotencyKey, $actor, $cashAccountId, $allocations) {
            $hash = hash('sha256', implode('|', [$invoicePaymentId, $amount, $cashAccountId ?? '']));

            $existing = PaymentRefund::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->replay($existing, $hash, $allocations);
            }

            $payment = InvoicePayment::query()->lockForUpdate()->find($invoicePaymentId);
            if (! $payment) {
                throw ValidationException::withMessages(['invoice_payment_id' => 'Платёж не найден.']);
            }

            // Re-check under the payment lock so concurrent retries serialise.
            $existing = PaymentRefund::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->replay($existing, $hash, $allocations);
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

            // Finance V2, Phase 1D — resolve which PaymentAllocation(s) this
            // refund reverses. Fetched fresh here, after the InvoicePayment
            // lock above, so it reflects the authoritative state for this
            // transaction (see the refund() docblock and
            // docs/finance-v2-architecture.md §19 Phase 1D). This is
            // strictly additive to the invoice-level non-refundable cap
            // already enforced above — it never replaces it.
            $paymentAllocations = $payment->allocations()->with('item')->get();

            // Finance V2, Phase 1D correction — the payment's own
            // PaymentAllocation coverage must be exactly ZERO (grandfathered
            // historical ambiguity) or exactly FULL (sums to the payment's
            // own amount) for any Phase 1D allocation logic to apply at
            // all. Anything in between is an anomaly canonical code should
            // never produce, and it FAILS CLOSED here — unconditionally,
            // whether this call omitted allocations or supplied them
            // explicitly. Never guessed, never normalized, never silently
            // downgraded to an unattributed refund: doing so would expand a
            // corrupted historical state instead of containing it.
            $totalAllocated = $this->totalAllocated($paymentAllocations);
            if ($paymentAllocations->isNotEmpty() && bccomp($totalAllocated, (string) $payment->amount, 2) !== 0) {
                throw ValidationException::withMessages(['allocations' => 'По этому платежу распределение по строкам счёта неполное, поэтому оформить возврат нельзя.']);
            }

            if ($allocations !== null) {
                $this->validateRefundAllocations($allocations, $paymentAllocations, $amount);
            } elseif ($paymentAllocations->isNotEmpty()) {
                if ($paymentAllocations->count() === 1) {
                    // Exactly one allocation — auto-attribute the whole
                    // refund to it, unless its item is non-refundable or the
                    // refund would exceed its own remaining capacity.
                    $only = $paymentAllocations->first();
                    if ($only->item->is_non_refundable) {
                        throw ValidationException::withMessages(['allocations' => 'Эта строка счёта не подлежит возврату.']);
                    }
                    $remaining = bcsub((string) $only->amount, $only->refundedAmount(), 2);
                    if (bccomp($amount, $remaining, 2) > 0) {
                        throw ValidationException::withMessages(['amount' => 'Сумма возврата превышает остаток по этой строке счёта.']);
                    }
                    $allocations = [['payment_allocation_id' => $only->id, 'amount' => $amount]];
                } else {
                    // Multiple allocations. Auto-distribution is safe only
                    // when this refund exactly exhausts every remaining
                    // refundable balance — the one split forced by the caps
                    // themselves, never a guess. Anything else requires an
                    // explicit staff-supplied split.
                    $eligible = $paymentAllocations->reject(fn ($a) => $a->item->is_non_refundable);
                    $remainingByAllocation = $eligible
                        ->mapWithKeys(fn ($a) => [$a->id => bcsub((string) $a->amount, $a->refundedAmount(), 2)])
                        ->filter(fn (string $remaining) => bccomp($remaining, '0.00', 2) > 0);
                    $totalRemaining = $remainingByAllocation->reduce(fn (string $carry, string $r) => bcadd($carry, $r, 2), '0.00');

                    if ($remainingByAllocation->isNotEmpty() && bccomp($amount, $totalRemaining, 2) === 0) {
                        $allocations = $remainingByAllocation
                            ->map(fn (string $r, int $id) => ['payment_allocation_id' => $id, 'amount' => $r])
                            ->values()
                            ->all();
                    } else {
                        throw ValidationException::withMessages(['allocations' => 'Укажите распределение возврата по строкам счёта.']);
                    }
                }
            }
            // else: $paymentAllocations is empty — zero-allocation
            // compatibility case. $allocations stays null; zero
            // PaymentRefundAllocation rows are created below.

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

            // Finance V2, Phase 1D — same transaction as the refund and its
            // CashTransaction, so all three succeed or roll back together.
            // $allocations is null whenever this refund is intentionally
            // left unattributed (see above).
            foreach ($allocations ?? [] as $line) {
                $refundAllocationAmount = $this->money((string) $line['amount']);
                $refundAllocation = PaymentRefundAllocation::create([
                    'payment_refund_id' => $refund->id,
                    'payment_allocation_id' => (int) $line['payment_allocation_id'],
                    'amount' => $refundAllocationAmount,
                ]);

                // Finance V2, Phase 2D corrective pass #3 (P0 Blocker 1D
                // — refunds must reduce period settlement). A
                // PaymentRefundAllocation always reverses ONE
                // PaymentAllocation, and a PaymentAllocation maps to AT
                // MOST one InstallmentCoveragePeriod (that table's own
                // uniqueness) — a genuine 1:1 mapping, mirroring exactly
                // what InvoicePaymentService::linkAllocationToCoveragePeriod()
                // already does for the payment side. Silently skipped
                // (not an error) when the original allocation was never
                // itself linked to a coverage period (e.g. Registration,
                // or any non-calendar-billed Fee) — nothing to reverse
                // there. NEVER deletes/rewrites the original
                // PaymentAllocationCoveragePeriod row — net settlement is
                // computed by summing both tables (see
                // InstallmentCoveragePeriod::netSettledAmount()), never
                // by mutating either.
                $originalPeriodLink = PaymentAllocationCoveragePeriod::where('payment_allocation_id', (int) $line['payment_allocation_id'])->first();
                if ($originalPeriodLink) {
                    PaymentRefundAllocationCoveragePeriod::create([
                        'payment_refund_allocation_id' => $refundAllocation->id,
                        'installment_coverage_period_id' => $originalPeriodLink->installment_coverage_period_id,
                        'amount' => $refundAllocationAmount,
                    ]);
                }
            }

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

            return $refund->fresh(['originalPayment', 'invoice', 'cashTransaction', 'executor', 'allocations']);
        });
    }

    /**
     * Finance V2, Phase 1D — validate an explicitly-supplied refund
     * allocation split. Never inferred, never proportional: every line must
     * reference a PaymentAllocation belonging to this same payment, be
     * strictly positive, reference a refundable InvoiceItem, appear at most
     * once, respect that allocation's own remaining refundable capacity,
     * and the lines must sum to exactly the refund amount.
     *
     * Callers must already have rejected an inconsistent (partial)
     * PaymentAllocation coverage on $payment before reaching here — see the
     * unconditional check in refund() itself, which fails closed regardless
     * of whether allocations were supplied explicitly or omitted.
     *
     * @param  array<int, array{payment_allocation_id: int, amount: string}>  $allocations
     */
    private function validateRefundAllocations(array $allocations, Collection $paymentAllocations, string $amount): void
    {
        if ($allocations === []) {
            throw ValidationException::withMessages(['allocations' => 'Укажите распределение возврата по строкам счёта.']);
        }

        $allocationsById = $paymentAllocations->keyBy('id');
        $seenIds = [];
        $sum = '0.00';
        $lineAmountsByAllocation = [];
        foreach ($allocations as $line) {
            if (! isset($line['payment_allocation_id'], $line['amount'])) {
                throw ValidationException::withMessages(['allocations' => 'Некорректные данные распределения возврата.']);
            }
            $allocationId = (int) $line['payment_allocation_id'];
            if (in_array($allocationId, $seenIds, true)) {
                throw ValidationException::withMessages(['allocations' => 'Строка возврата указана более одного раза.']);
            }
            $seenIds[] = $allocationId;

            $allocation = $allocationsById->get($allocationId);
            if (! $allocation) {
                throw ValidationException::withMessages(['allocations' => 'Распределение платежа не принадлежит указанному платежу.']);
            }
            if ($allocation->item->is_non_refundable) {
                throw ValidationException::withMessages(['allocations' => 'Эта строка счёта не подлежит возврату.']);
            }

            $lineAmount = $this->money((string) $line['amount']);
            if (bccomp($lineAmount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['allocations' => 'Сумма распределения возврата должна быть больше нуля.']);
            }
            $sum = bcadd($sum, $lineAmount, 2);
            $lineAmountsByAllocation[$allocationId] = $lineAmount;
        }

        if (bccomp($sum, $amount, 2) !== 0) {
            throw ValidationException::withMessages(['allocations' => 'Сумма распределения должна совпадать с суммой возврата.']);
        }

        // Per-allocation outstanding cap — an allocation can never be
        // refunded more than its own amount, across all refunds, historical
        // plus this one.
        foreach ($lineAmountsByAllocation as $allocationId => $newAmount) {
            $allocation = $allocationsById->get($allocationId);
            $remaining = bcsub((string) $allocation->amount, $allocation->refundedAmount(), 2);
            if (bccomp($newAmount, $remaining, 2) > 0) {
                throw ValidationException::withMessages(['allocations' => 'Сумма возврата превышает остаток по выбранной строке счёта.']);
            }
        }
    }

    /** @param  Collection<int, \App\Models\PaymentAllocation>  $paymentAllocations */
    private function totalAllocated(Collection $paymentAllocations): string
    {
        return $this->money((string) $paymentAllocations->reduce(fn (string $carry, $allocation) => bcadd($carry, (string) $allocation->amount, 2), '0.00'));
    }

    /**
     * Finance V2, Phase 1D correction — idempotency must now also protect
     * the refund's allocation semantics, not just amount/payment/account.
     * The base hash (invoicePaymentId|amount|cashAccountId) is deliberately
     * left unchanged: every pre-Phase-1D PaymentRefund's idempotency_hash
     * was computed without allocations and must keep validating exactly as
     * before (Option B from the design audit — compare the CURRENT
     * request's allocation semantics against the ALREADY-PERSISTED
     * PaymentRefundAllocation rows of the found refund, rather than fold
     * allocations into the hash itself, which would have broken every
     * historical hash's shape).
     *
     * Comparison rule, deliberately simple and never re-resolving anything:
     *   - $allocations omitted (null) on this call is always compatible
     *     with any persisted outcome, whether that outcome is an old
     *     historical zero-row refund, a Phase 1D zero-row (Case A)
     *     compatibility refund, or a Phase 1D auto-attributed (Case B/D)
     *     refund. A caller that omits the argument is trusting the service
     *     to resolve it, exactly as the original call did — never
     *     re-resolved here, so a later change in remaining capacity can
     *     never turn a legitimate replay into a failure.
     *   - $allocations supplied explicitly must match the persisted
     *     PaymentRefundAllocation rows exactly, as canonical
     *     {payment_allocation_id => amount} maps (order-independent,
     *     decimal-normalized, duplicate ids merged by summing). Any
     *     difference — including a genuinely different split for the same
     *     total amount — is an idempotency conflict, not a silent replay.
     *
     * @param  ?array<int, array{payment_allocation_id: int, amount: string}>  $allocations
     */
    private function replay(PaymentRefund $refund, string $hash, ?array $allocations): PaymentRefund
    {
        if (! hash_equals((string) $refund->idempotency_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ повторного запроса уже использован для другого возврата.']);
        }

        if ($allocations !== null) {
            $requested = $this->canonicalAllocationMap($allocations);
            $persisted = $this->canonicalAllocationMap(
                $refund->allocations()->get()->map(fn ($a) => ['payment_allocation_id' => $a->payment_allocation_id, 'amount' => (string) $a->amount])->all()
            );
            if ($requested != $persisted) {
                throw ValidationException::withMessages(['idempotency_key' => 'Ключ повторного запроса уже использован для возврата с другим распределением по строкам счёта.']);
            }
        }

        return $refund;
    }

    /**
     * Canonical, order-independent, decimal-normalized representation of a
     * refund allocation payload for idempotency comparison: duplicate
     * payment_allocation_id entries are merged by summing (never rejected
     * here — this is a comparison helper, not input validation).
     *
     * @param  array<int, array{payment_allocation_id: int, amount: string}>  $allocations
     * @return array<int, string>
     */
    private function canonicalAllocationMap(array $allocations): array
    {
        $map = [];
        foreach ($allocations as $line) {
            $id = (int) $line['payment_allocation_id'];
            $amount = $this->money((string) $line['amount']);
            $map[$id] = bcadd($map[$id] ?? '0.00', $amount, 2);
        }
        ksort($map);

        return $map;
    }

    private function money(string $value): string
    {
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Укажите корректную сумму возврата.']);
        }

        return bcadd($value, '0', 2);
    }
}
