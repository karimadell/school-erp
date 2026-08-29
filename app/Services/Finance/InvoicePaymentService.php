<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceInstallment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoicePaymentService
{
    public function __construct(private CashSessionService $sessions)
    {
    }

    /**
     * @param  ?array<int, array{invoice_item_id: int, amount: string}>  $allocations
     *         Finance V2, Phase 1A (docs/finance-v2-architecture.md §7).
     *         Which InvoiceItem(s) this payment pays down, and how much of
     *         each. Optional and backward-compatible:
     *           - Omitted (null) against an invoice with exactly one
     *             InvoiceItem: one PaymentAllocation is created
     *             automatically for the full payment amount — no caller
     *             change required.
     *           - Omitted (null) against a multi-item invoice: no
     *             PaymentAllocation rows are created at all (Phase 1A's
     *             intentional, temporary "unallocated" state — Charge &
     *             Collect, Classic Invoice, and existing-invoice payment
     *             are not yet updated to supply this; that is Phase 1B).
     *             This is never treated as an error in Phase 1A.
     *           - Supplied explicitly: every invoice_item_id must belong
     *             to this same invoice, every amount must be > 0, and the
     *             amounts must sum to exactly $amount — validated with the
     *             same decimal-string rigor as the payment amount itself.
     *             Never inferred, never proportional.
     */
    public function record(
        int $invoiceId,
        int $cashAccountId,
        string $amount,
        string $paymentMethod,
        string $idempotencyKey,
        ?User $actor = null,
        ?string $reference = null,
        ?string $notes = null,
        ?int $installmentId = null,
        ?array $allocations = null,
    ): InvoicePayment {
        $amount = $this->money($amount);
        if (! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Укажите корректный ключ повторного запроса.']);
        }
        if (! in_array($paymentMethod, ['cash', 'bank', 'card', 'transfer', 'instapay'], true)) {
            throw ValidationException::withMessages(['payment_method' => 'Выбран недопустимый способ оплаты.']);
        }
        $hash = hash('sha256', implode('|', [$invoiceId, $installmentId, $cashAccountId, $amount, $paymentMethod]));

        return DB::transaction(function () use ($invoiceId, $installmentId, $cashAccountId, $amount, $paymentMethod, $idempotencyKey, $actor, $reference, $notes, $hash, $allocations) {
            $existing = InvoicePayment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->replay($existing, $hash);
            }

            $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);
            if (! $invoice) {
                throw ValidationException::withMessages(['invoice_id' => 'Счёт не найден.']);
            }

            if ($invoice->status === Invoice::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['invoice_id' => 'Счёт аннулирован и не может быть оплачен.']);
            }

            // Recheck after serializing on the invoice row so concurrent retries
            // cannot pass the first lookup together.
            $existing = InvoicePayment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->replay($existing, $hash);
            }

            if (bccomp($amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Сумма платежа должна быть больше нуля.']);
            }

            $paid = $this->money((string) InvoicePayment::query()->where('invoice_id', $invoice->id)->sum('amount'));
            $remaining = bcsub($this->money($invoice->total_amount), $paid, 2);
            if (bccomp($remaining, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Счёт уже полностью оплачен.']);
            }
            if (bccomp($amount, $remaining, 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'Платёж не может превышать остаток по счёту.']);
            }

            // Finance V2, Phase 1A — resolve which InvoiceItem(s) this
            // payment pays down. Explicit allocations are validated as
            // given; omitted allocations auto-resolve only when the
            // invoice has exactly one item (no ambiguity to guess at).
            // Any other omitted case is left unallocated — Phase 1A's
            // intentional, temporary, non-error state (see the record()
            // docblock and docs/finance-v2-architecture.md §19 Phase 1A).
            $invoiceItems = $invoice->items()->get();
            if ($allocations !== null) {
                $this->validateAllocations($allocations, $invoice, $invoiceItems, $amount, $paid);
            } elseif ($invoiceItems->count() === 1) {
                $allocations = [[
                    'invoice_item_id' => $invoiceItems->first()->id,
                    'amount' => $amount,
                ]];
            }

            $installment = null;
            if (! $invoice->installments()->exists() && $installmentId === null) {
                $installment = InvoiceInstallment::create([
                    'invoice_id'=>$invoice->id, 'name_ru'=>'Полная оплата', 'sequence'=>1,
                    'due_date'=>$invoice->due_date ?? today(), 'amount'=>$invoice->total_amount,
                    'paid_amount'=>$paid, 'remaining_amount'=>$remaining, 'status'=>InvoiceInstallment::STATUS_PENDING,
                ]);
                $installmentId = $installment->id;
            }
            if ($invoice->installments()->exists()) {
                if ($installmentId === null) {
                    $outstandingInstallments = $invoice->installments()->where('remaining_amount','>','0')->lockForUpdate()->get();
                    if ($outstandingInstallments->count() === 1) $installmentId = $outstandingInstallments->first()->id;
                }
                $installment = InvoiceInstallment::query()->lockForUpdate()->find($installmentId);
                if (! $installment || $installment->invoice_id !== $invoice->id) {
                    throw ValidationException::withMessages(['invoice_installment_id' => 'Выберите этап рассрочки этого счёта.']);
                }
                if (bccomp($amount, (string) $installment->remaining_amount, 2) > 0) {
                    throw ValidationException::withMessages(['amount' => 'Платёж не может превышать остаток по выбранному этапу.']);
                }
            }

            $account = CashAccount::query()->lockForUpdate()->find($cashAccountId);
            if (! $account) {
                throw ValidationException::withMessages(['cash_account_id' => 'Касса не найдена.']);
            }
            if (! $account->is_active) {
                throw ValidationException::withMessages(['cash_account_id' => 'Выбранная касса неактивна.']);
            }
            // Cash Operations Phase 4 — the owner's holding account is never
            // a valid destination for an ordinary student payment, no matter
            // which caller reaches this method or what it passed as
            // $cashAccountId. Canonical cash/bank/instapay resolution (so a
            // tampered id can't redirect money) happens one layer up, at the
            // student-facing controllers/services that first see untrusted
            // input — see CashAccount::canonicalRoleForMethod().
            if ($account->role === CashAccount::ROLE_OWNER) {
                throw ValidationException::withMessages(['cash_account_id' => 'Счёт владельца нельзя использовать для оплаты учеником.']);
            }

            // Phase 3 — strict cash-session rule: physical cash cannot enter a
            // drawer without an open shift. Non-cash methods (bank/card/transfer)
            // do not touch the physical drawer and keep their existing behaviour.
            $cashSessionId = null;
            if ($paymentMethod === CashTransaction::METHOD_CASH && $account->isCashDrawer()) {
                $session = $this->sessions->activeFor($account, lock: true);
                if (! $session) {
                    throw ValidationException::withMessages([
                        'payment_method' => 'Для приёма наличных нужна открытая кассовая смена.',
                    ]);
                }
                $cashSessionId = $session->id;
            }

            $payment = InvoicePayment::create([
                'invoice_id' => $invoice->id,
                'invoice_installment_id' => $installment?->id,
                'cash_account_id' => $account->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'paid_at' => now(),
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => $actor?->id,
                'idempotency_key' => $idempotencyKey,
                'idempotency_hash' => $hash,
            ]);
            $payment->payment_number = InvoicePayment::numberFor($payment->id, $payment->created_at->format('Y'));
            $payment->save();

            // Finance V2, Phase 1A — same transaction as the payment and
            // its CashTransaction, so all three succeed or roll back
            // together. $allocations is null whenever Phase 1A leaves this
            // payment intentionally unallocated (see above).
            foreach ($allocations ?? [] as $allocation) {
                PaymentAllocation::create([
                    'invoice_payment_id' => $payment->id,
                    'invoice_item_id' => (int) $allocation['invoice_item_id'],
                    'amount' => $this->money((string) $allocation['amount']),
                ]);
            }

            CashTransaction::create([
                'cash_account_id' => $account->id,
                'cash_session_id' => $cashSessionId, // FK-at-creation; null for non-cash
                'created_by' => $actor?->id,
                'invoice_payment_id' => $payment->id,
                'amount' => $amount,
                'type' => CashTransaction::TYPE_IN,
                'category' => CashTransaction::CATEGORY_INCOME,
                'description' => "Платёж {$payment->payment_number} по счёту {$invoice->display_number}",
            ]);

            $newPaid = bcadd($paid, $amount, 2);
            $newRemaining = bcsub($this->money($invoice->total_amount), $newPaid, 2);
            $invoice->forceFill([
                'paid_amount' => $newPaid,
                'remaining_amount' => $newRemaining,
                'status' => bccomp($newRemaining, '0.00', 2) === 0 ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIAL,
                'paid_at' => bccomp($newRemaining, '0.00', 2) === 0 ? now() : null,
                'payment_method' => $paymentMethod,
                'cash_account_id' => $account->id,
            ])->save();

            $installment?->refreshStatus();

            AuditLog::create([
                'user_id' => $actor?->id,
                'action' => 'payment_recorded',
                'model' => 'InvoicePayment',
                'model_id' => $payment->id,
                'new_values' => [
                    'payment_number' => $payment->payment_number,
                    'invoice_id' => $invoice->id,
                    'amount' => $amount,
                    'payment_method' => $paymentMethod,
                ],
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $payment->fresh(['cashTransaction', 'allocations']);
        });
    }

    private function replay(InvoicePayment $payment, string $hash): InvoicePayment
    {
        if (! hash_equals((string) $payment->idempotency_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ повторного запроса уже использован для другого платежа.']);
        }

        return $payment;
    }

    /**
     * Finance V2, Phase 1B — whether every payment recorded so far against
     * this invoice is fully represented in PaymentAllocation rows
     * (SUM(payments) === SUM(allocations)) AND no refund has ever touched
     * any of its payments. Only a "clean" invoice has a trustworthy
     * per-item remaining-allocatable figure; callers use this to decide
     * whether to offer/require explicit per-item allocation UI at all
     * (docs/finance-v2-architecture.md §19 Phase 1B). A single-item
     * invoice is always clean by construction (Phase 1A auto-allocates
     * every payment against its one item), and a brand-new invoice is
     * trivially clean (zero prior payments).
     *
     * The refund condition exists because InvoiceRefundService::refund()
     * never mutates or deletes PaymentAllocation rows — there is no
     * payment_refund_allocations table yet (Phase 1D) to say which item a
     * refund gave money back from. Without it, PaymentAllocation sums stay
     * gross forever: an item that was fully allocated and then partially
     * refunded still reads as fully allocated, so its true remaining
     * capacity is unknowable, not just untrusted. Any refund against this
     * invoice — full, partial, on a single- or multi-line payment — makes
     * it allocation-ambiguous; never guessed at which item(s) the refund
     * came from.
     */
    public function isAllocationClean(Invoice $invoice): bool
    {
        if ($this->hasAnyRefunds($invoice->id)) {
            return false;
        }

        $paid = $this->money((string) InvoicePayment::query()->where('invoice_id', $invoice->id)->sum('amount'));
        $allocated = $this->money((string) PaymentAllocation::query()
            ->whereIn('invoice_item_id', $invoice->items()->pluck('id'))
            ->sum('amount'));

        return bccomp($paid, $allocated, 2) === 0;
    }

    /** Whether any PaymentRefund exists against any payment on this invoice. */
    private function hasAnyRefunds(int $invoiceId): bool
    {
        return PaymentRefund::query()->where('invoice_id', $invoiceId)->exists();
    }

    /**
     * Finance V2, Phase 1B — each of this invoice's InvoiceItems mapped to
     * its currently allocatable remaining amount (line amount minus
     * everything already allocated against it, across all prior payments).
     * Only meaningful on an allocation-clean invoice — check
     * isAllocationClean() first; the figure cannot be trusted otherwise.
     *
     * @return Collection<int, string> amount strings keyed by invoice_item_id
     */
    public function remainingAllocatableByItem(Invoice $invoice): Collection
    {
        $items = $invoice->items()->get();
        $allocatedByItem = PaymentAllocation::query()
            ->whereIn('invoice_item_id', $items->pluck('id'))
            ->selectRaw('invoice_item_id, SUM(amount) as total')
            ->groupBy('invoice_item_id')
            ->pluck('total', 'invoice_item_id');

        return $items->mapWithKeys(function ($item) use ($allocatedByItem) {
            $allocated = $this->money((string) ($allocatedByItem->get($item->id) ?? '0.00'));

            return [$item->id => bcsub($this->money((string) $item->amount), $allocated, 2)];
        });
    }

    /**
     * Finance V2, Phase 1B — validate an explicitly-supplied allocation
     * split. Never inferred, never proportional: every line must belong to
     * this same invoice, be strictly positive, and the lines must sum to
     * exactly the payment amount.
     *
     * Phase 1B additionally enforces the per-InvoiceItem outstanding cap,
     * which requires knowing each item's already-allocated amount from
     * existing PaymentAllocation rows. That figure is only trustworthy when
     * this invoice is "allocation-clean" — i.e. every payment recorded
     * against it so far is fully represented in PaymentAllocation rows
     * (SUM(payments) === SUM(allocations)), AND no refund has ever touched
     * any of its payments (see isAllocationClean() for why a refund alone
     * is enough to make the per-item cap untrustworthy — PaymentAllocation
     * rows are never adjusted by InvoiceRefundService::refund(), and there
     * is no payment_refund_allocations table yet to say which item a
     * refund gave money back from). A pre-Phase-1 (or Phase 1A-unallocated)
     * invoice can have historical payments with no allocation rows at all;
     * for those too, we cannot know which item(s) the old money actually
     * paid down, so we refuse to guess a per-item remaining capacity and
     * reject explicit allocation instead — the caller must fall back to
     * Phase 1A's unallocated path for that invoice
     * (docs/finance-v2-architecture.md §19 Phase 1B).
     *
     * @param  array<int, array{invoice_item_id: int, amount: string}>  $allocations
     * @param  string  $paidSoFar  Total InvoicePayment amount already recorded
     *         against this invoice, before this new payment (same value the
     *         caller used for its own remaining-balance check).
     */
    private function validateAllocations(array $allocations, Invoice $invoice, Collection $invoiceItems, string $amount, string $paidSoFar): void
    {
        if ($allocations === []) {
            throw ValidationException::withMessages(['allocations' => 'Укажите распределение платежа по услугам.']);
        }

        if ($this->hasAnyRefunds($invoice->id)) {
            // A refund against this invoice means PaymentAllocation sums no
            // longer reflect true outstanding capacity per item (refunds
            // never adjust them), and we have no way to know which item(s)
            // a refund actually returned money from. Never guess — fall
            // back to Phase 1A's unallocated path for this invoice.
            throw ValidationException::withMessages(['allocations' => 'По этому счёту были возвраты, поэтому распределить новый платёж по строкам счёта нельзя.']);
        }

        $itemIds = $invoiceItems->pluck('id');
        $allocatedByItem = PaymentAllocation::query()
            ->whereIn('invoice_item_id', $itemIds)
            ->selectRaw('invoice_item_id, SUM(amount) as total')
            ->groupBy('invoice_item_id')
            ->pluck('total', 'invoice_item_id');

        $allocatedSoFar = $this->money((string) $allocatedByItem->reduce(fn (string $carry, $value) => bcadd($carry, (string) $value, 2), '0.00'));
        if (bccomp($paidSoFar, $allocatedSoFar, 2) !== 0) {
            // Historical unallocated payment(s) exist on this invoice — we
            // cannot determine each item's true remaining allocatable
            // amount, so an explicit per-item split cannot be safely
            // validated or enforced. Never guess.
            throw ValidationException::withMessages(['allocations' => 'По этому счёту есть более ранние платежи без разбивки по услугам, поэтому распределить новый платёж по строкам счёта нельзя.']);
        }

        $validItemIds = $itemIds;
        $itemsById = $invoiceItems->keyBy('id');
        $sum = '0.00';
        $lineAmountsByItem = [];
        foreach ($allocations as $allocation) {
            if (! isset($allocation['invoice_item_id'], $allocation['amount'])) {
                throw ValidationException::withMessages(['allocations' => 'Некорректные данные распределения платежа.']);
            }
            $itemId = (int) $allocation['invoice_item_id'];
            if (! $validItemIds->contains($itemId)) {
                throw ValidationException::withMessages(['allocations' => 'Строка счёта не принадлежит указанному счёту.']);
            }
            $lineAmount = $this->money((string) $allocation['amount']);
            if (bccomp($lineAmount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['allocations' => 'Сумма распределения должна быть больше нуля.']);
            }
            $sum = bcadd($sum, $lineAmount, 2);
            $lineAmountsByItem[$itemId] = bcadd($lineAmountsByItem[$itemId] ?? '0.00', $lineAmount, 2);
        }

        if (bccomp($sum, $amount, 2) !== 0) {
            throw ValidationException::withMessages(['allocations' => 'Сумма распределения должна совпадать с суммой платежа.']);
        }

        // Per-item outstanding cap — an item can never receive more than its
        // own line amount across all payments, historical plus this one.
        foreach ($lineAmountsByItem as $itemId => $newAmount) {
            $item = $itemsById->get($itemId);
            $alreadyAllocated = $this->money((string) ($allocatedByItem->get($itemId) ?? '0.00'));
            $itemRemaining = bcsub($this->money((string) $item->amount), $alreadyAllocated, 2);
            if (bccomp($newAmount, $itemRemaining, 2) > 0) {
                throw ValidationException::withMessages(['allocations' => 'Сумма распределения превышает остаток по выбранной строке счёта.']);
            }
        }
    }

    private function money(string $value): string
    {
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Укажите корректную сумму платежа.']);
        }

        return bcadd($value, '0', 2);
    }
}
