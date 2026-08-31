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
    public function __construct(private CashSessionService $sessions, private PaymentAllocationAnalyzer $analyzer)
    {
    }

    /**
     * @param  ?array<int, array{invoice_item_id: int, amount: string}>  $allocations
     *         Finance V2, Phase 1A/1C (docs/finance-v2-architecture.md §7,
     *         §19 Phase 1C). Which InvoiceItem(s) this payment pays down,
     *         and how much of each:
     *           - Omitted (null) against an invoice with exactly one
     *             InvoiceItem: one PaymentAllocation is created
     *             automatically for the full payment amount — no caller
     *             change required.
     *           - Omitted (null) against a multi-item invoice: legal only
     *             when the invoice is allocation-ambiguous (a historical
     *             unallocated payment, or any refund, already makes its
     *             true per-item remaining capacity unknowable — see
     *             isAllocationClean()). Zero PaymentAllocation rows are
     *             created. Never automatically distributed, never
     *             inferred. Against an allocation-clean multi-item invoice
     *             this is now rejected (Phase 1C) instead of silently
     *             leaving the payment unallocated (Phase 1A's closed
     *             default) — the clean/ambiguous decision is made here,
     *             inside this method's own transaction and invoice lock,
     *             never trusted from a caller's own (possibly stale)
     *             pre-check.
     *           - Supplied explicitly: every invoice_item_id must belong
     *             to this same invoice, every amount must be > 0, and the
     *             amounts must sum to exactly $amount — validated with the
     *             same decimal-string rigor as the payment amount itself.
     *             Never inferred, never proportional. Rejected outright
     *             against an allocation-ambiguous invoice (existing
     *             behavior, unchanged by Phase 1C — see
     *             validateAllocations()).
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

            // Finance V2, Phase 1B.1 — net of refunds, not gross InvoicePayment
            // sum. InvoiceRefundService::refund() never writes a negative
            // InvoicePayment row, so a gross sum here would never reflect a
            // refund: an invoice paid in full and then partially refunded
            // would still read as "fully paid" and reject a legitimate
            // replacement payment. Invoice::netPaidAmount() is the same
            // canonical (gross payments − gross refunds) calculation
            // Invoice::refreshPaymentStatus() already uses after a refund —
            // reusing it here keeps record()'s own view of "already paid"
            // consistent with the invoice's own persisted state instead of
            // diverging from it.
            $paid = $this->money($invoice->netPaidAmount());
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
            $invoiceItems = $invoice->items()->get();
            if ($allocations !== null) {
                $this->validateAllocations($allocations, $invoice, $invoiceItems, $amount);
            } elseif ($invoiceItems->count() === 1) {
                $allocations = [[
                    'invoice_item_id' => $invoiceItems->first()->id,
                    'amount' => $amount,
                ]];
            } elseif ($invoiceItems->count() > 1) {
                // Finance V2, Phase 1C/1E (docs/finance-v2-architecture.md
                // §19 Phase 1C, Phase 1E) — omitted allocations against a
                // multi-item invoice are only acceptable when the invoice is
                // allocation-ambiguous (a historical unallocated/partial
                // payment, or an unattributed/partial/cross-referenced
                // refund, makes its true per-item remaining capacity
                // unknowable — see isAllocationClean()/analyzeAllocations()).
                // A genuinely allocation-clean multi-item invoice has no
                // such excuse: every caller reaching this point must supply
                // an explicit split, so omitting one is rejected instead of
                // silently leaving the payment unallocated (Phase 1A's old,
                // now-closed, default for this case).
                //
                // Decided here, inside record()'s own transaction and after
                // the invoice lock above, via isAllocationCleanLocked() —
                // never trusting a caller's own (possibly stale,
                // pre-lock) isAllocationClean() check. Both call the exact
                // same analyzeAllocations() invariant, so "clean" means the
                // same thing on both the explicit and omitted paths.
                if ($this->isAllocationCleanLocked($invoice, $invoiceItems)) {
                    throw ValidationException::withMessages(['allocations' => 'Укажите распределение платежа по услугам.']);
                }
                // Ambiguous multi-item invoice — Phase 1C's intentional,
                // temporary compatibility exception. $allocations stays
                // null; zero PaymentAllocation rows are created below. Never
                // guess which item(s) this money actually pays down.
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
     * Finance V2, Phase 1E (docs/finance-v2-architecture.md §19 Phase 1E) —
     * whether every payment and every refund recorded so far against this
     * invoice is fully, unambiguously attributable to specific
     * InvoiceItems, making its per-item remaining-allocatable figure
     * (remainingAllocatableByItem()) trustworthy. Callers use this to
     * decide whether to offer/require explicit per-item allocation UI at
     * all. A single-item invoice is always clean by construction (Phase 1A
     * auto-allocates every payment against its one item), and a brand-new
     * invoice is trivially clean (zero prior payments/refunds).
     *
     * Replaces Phase 1C's blunt "any refund ⇒ ambiguous" rule with the
     * precise per-payment/per-refund invariant Phase 1D's
     * PaymentRefundAllocation data now makes possible — see
     * analyzeAllocations() for the exact rules.
     */
    public function isAllocationClean(Invoice $invoice): bool
    {
        return $this->analyzeAllocations($invoice, $invoice->items()->get())['clean'];
    }

    /**
     * Finance V2, Phase 1E — the authoritative, inside-the-lock cleanliness
     * check record() itself uses to decide whether an omitted allocation is
     * legal. Both this and the public, advisory isAllocationClean() call
     * the exact same analyzeAllocations() invariant, so the two can never
     * silently disagree on what "clean" means — isAllocationClean() may
     * simply observe a staler view of the same underlying facts (see
     * record()'s own comment on why that staleness is safe).
     *
     * @param  Collection<int, \App\Models\InvoiceItem>  $invoiceItems
     */
    private function isAllocationCleanLocked(Invoice $invoice, Collection $invoiceItems): bool
    {
        return $this->analyzeAllocations($invoice, $invoiceItems, forUpdate: true)['clean'];
    }

    /**
     * Finance V2, Phase 1E (docs/finance-v2-architecture.md §19 Phase 1E) —
     * the single, authoritative source of both the allocation-clean verdict
     * and the net (gross allocated minus gross refunded) per-InvoiceItem
     * figure. Every public/private cleanliness or remaining-capacity method
     * on this class funnels through here, so there is exactly one
     * definition of "clean" and one definition of "net remaining" — never
     * two competing implementations that could silently disagree.
     *
     * An invoice is allocation-clean iff ALL of the following hold:
     *
     *   1. Every InvoicePayment on this invoice is canonical — a positive
     *      amount (a legacy negative-amount row, from before
     *      InvoiceRefundService existed, is never clean; Phase 1E does not
     *      redesign legacy refund history) — AND its PaymentAllocation
     *      coverage is exactly FULL (sums to the payment's own amount).
     *      ZERO coverage does NOT make a payment clean — it is
     *      grandfathered historical data that is allowed to keep existing,
     *      but its presence makes the WHOLE INVOICE allocation-ambiguous,
     *      exactly like partial (strictly-between) coverage, which is
     *      corruption canonical code never produces but a read-time audit
     *      must not assume never happened. Only FULL coverage is clean;
     *      ZERO and PARTIAL both poison the invoice.
     *   2. Every PaymentAllocation on this invoice belongs to one of this
     *      invoice's own InvoiceItems.
     *   3. Every PaymentRefund against one of this invoice's payments has
     *      PaymentRefundAllocation coverage that is exactly FULL. ZERO
     *      coverage (historical/unattributed — allowed to exist, never
     *      poisons only itself) and PARTIAL coverage (anomalous) both
     *      poison the WHOLE invoice; only FULL coverage is clean.
     *   4. Every PaymentRefundAllocation references a PaymentAllocation
     *      belonging to the SAME InvoicePayment the refund refunds — never
     *      a different payment.
     *   5. No PaymentAllocation is refunded, cumulatively across every
     *      refund against it, more than its own amount.
     *   6. The resulting net allocation per InvoiceItem (gross allocated
     *      minus gross refunded) is never negative and never exceeds the
     *      item's own amount.
     *
     * ONE genuinely ambiguous or anomalous event anywhere on the invoice
     * poisons the WHOLE invoice for future item-level allocation decisions
     * — never scoped down to "clean except this one payment/item/refund".
     * No backfill, no proportional inference, no guessing.
     *
     * Every rule above is checked PER PAYMENT, PER REFUND, and PER
     * PaymentAllocation — never as an invoice-wide aggregate sum. An
     * aggregate-only comparison (Phase 1B/1C's original approach) cannot
     * distinguish a genuinely clean invoice from one with compensating
     * corruption — e.g. one payment over-allocated while another is
     * under-allocated by the same amount, summing to a false-clean total.
     *
     * --- $forUpdate / lock-order safety (concurrency correction) ---------
     *
     * true only from record()'s own authoritative, inside-the-transaction,
     * post-Invoice::lockForUpdate() call path. MySQL's default REPEATABLE
     * READ isolation pins a transaction's plain (non-locking) reads to the
     * snapshot from its FIRST read of any kind — in record() that is the
     * idempotency-key lookup, which runs before the Invoice lock — so a
     * later plain SELECT in that same transaction can still reflect a
     * pre-lock snapshot even once the Invoice row itself is confirmed
     * current via its own locking read (locking reads always see the
     * latest committed row; that guarantee is per-row, not per-snapshot,
     * and does not "refresh" the snapshot other statements in the same
     * transaction fall back to).
     *
     * An earlier revision of this method closed that gap by also taking
     * SELECT ... FOR UPDATE on InvoicePayment (via
     * InvoicePayment::where('invoice_id', ...)). That is unsafe: it can
     * lock ANY existing payment row on this invoice, including the one
     * InvoiceRefundService::refund() locks FIRST (before Invoice) via
     * InvoicePayment::lockForUpdate()->find($invoicePaymentId). A
     * concurrent record() (Invoice → InvoicePayment) and refund()
     * (InvoicePayment → Invoice) on the same invoice then form a genuine
     * wait-for cycle — InnoDB's deadlock detector kills one transaction,
     * surfacing as a spurious failure under ordinary concurrent traffic,
     * not a hang, but wrong to ship.
     *
     * The fix is to never lock InvoicePayment at all, and only take
     * SELECT ... FOR UPDATE on tables InvoiceRefundService never
     * independently row-locks by id: PaymentAllocation (scoped by this
     * invoice's own InvoiceItem ids — refund() only ever reads it
     * plainly, via $payment->allocations()) and PaymentRefund + its
     * eager-loaded PaymentRefundAllocation rows (refund() only ever
     * INSERTs new rows into these two tables, under its own already-held
     * Invoice lock, never re-locks an existing row from elsewhere). Locking
     * exactly these tables cannot deadlock against refund()'s
     * InvoicePayment → Invoice order, because refund() never contends for
     * a lock on them at all.
     *
     * This is provably sufficient, leaving InvoicePayment itself a plain
     * (potentially stale) read purely to enumerate payments and detect
     * ZERO/PARTIAL per-payment coverage: canonical code can NEVER
     * manufacture fresh ambiguity from a truly clean invoice — every path
     * that creates a zero-coverage InvoicePayment (the Phase 1C/1E
     * omitted-allocation compatibility branch) or a zero-coverage
     * PaymentRefund (InvoiceRefundService's Case A) only activates when
     * THAT SAME transaction's own, freshly-locked analyzeAllocations()
     * call already found the invoice ambiguous — so any ambiguity a
     * concurrent transaction could add was, transitively, always already
     * present beforehand. The one thing a stale InvoicePayment read could
     * still misreport — the exact gross/net amounts, if a concurrent
     * payment consumed some item's capacity — is exactly what locking
     * PaymentAllocation (current read) directly guards against; nothing
     * about the clean/ambiguous verdict itself depends on InvoicePayment
     * being current.
     *
     * A stale read on the purely advisory, non-transactional
     * isAllocationClean() path (forUpdate: false) needs none of this — it
     * only ever pushes an invoice into the safe "ambiguous" fallback,
     * never the unsafe direction, and record() always re-validates
     * authoritatively regardless.
     *
     * @param  Collection<int, \App\Models\InvoiceItem>  $invoiceItems
     * @return array{clean: bool, netByItem: Collection<int, string>}
     */
    private function analyzeAllocations(Invoice $invoice, Collection $invoiceItems, bool $forUpdate = false): array
    {
        $itemIds = $invoiceItems->pluck('id');

        // Never locked — see the "$forUpdate / lock-order safety" note
        // above for the proof that a plain read here cannot yield a false
        // "clean" verdict, and why locking it WOULD create a deadlock
        // against InvoiceRefundService's InvoicePayment → Invoice order.
        $payments = InvoicePayment::query()->where('invoice_id', $invoice->id)->get();

        // Locked (when $forUpdate) for a current, non-stale read of the
        // actual capacity consumed — InvoiceRefundService never
        // independently row-locks PaymentAllocation, so this cannot
        // deadlock against it.
        $allocationsQuery = PaymentAllocation::query()->whereIn('invoice_item_id', $itemIds);
        if ($forUpdate) {
            $allocationsQuery->lockForUpdate();
        }
        $allocations = $allocationsQuery->get();

        // Locked (when $forUpdate) the same way, and for the same reason —
        // InvoiceRefundService only ever INSERTs new PaymentRefund /
        // PaymentRefundAllocation rows (under its own already-held Invoice
        // lock), never independently row-locks an existing one, so this
        // cannot deadlock against it either.
        $refundsQuery = PaymentRefund::query()->where('invoice_id', $invoice->id);
        if ($forUpdate) {
            $refundsQuery->lockForUpdate()->with(['allocations' => fn ($q) => $q->lockForUpdate()]);
        } else {
            $refundsQuery->with('allocations');
        }
        $refunds = $refundsQuery->get();

        // Finance V2, Phase 2A extraction — the pure arithmetic/classification
        // that used to live inline here now lives in PaymentAllocationAnalyzer
        // (stateless, no queries, no locking), so the Collections read model
        // can reuse the exact same invariant. Nothing above this line moved:
        // the fetching, the lock order, and the $forUpdate conditionals are
        // untouched, preserving the deadlock-avoidance proof in this method's
        // own docblock.
        return $this->analyzer->analyzeInvoice($invoiceItems, $payments, $allocations, $refunds);
    }

    /**
     * Finance V2, Phase 1E — each of this invoice's InvoiceItems mapped to
     * its currently allocatable remaining amount: line amount minus its NET
     * allocation (everything already allocated against it, across all
     * prior payments, minus everything already attributed back to it by a
     * refund). Only meaningful on an allocation-clean invoice — check
     * isAllocationClean() first; the figure cannot be trusted otherwise.
     *
     * @return Collection<int, string> amount strings keyed by invoice_item_id
     */
    public function remainingAllocatableByItem(Invoice $invoice): Collection
    {
        $items = $invoice->items()->get();
        $netByItem = $this->analyzeAllocations($invoice, $items)['netByItem'];

        return $items->mapWithKeys(fn ($item) => [
            $item->id => bcsub($this->money((string) $item->amount), $this->money((string) $netByItem->get($item->id, '0.00')), 2),
        ]);
    }

    /**
     * Finance V2, Phase 1B/1E — validate an explicitly-supplied allocation
     * split. Never inferred, never proportional: every line must belong to
     * this same invoice, be strictly positive, and the lines must sum to
     * exactly the payment amount.
     *
     * Enforces the per-InvoiceItem outstanding cap against each item's NET
     * remaining capacity (gross allocated minus gross refunded —
     * analyzeAllocations()'s netByItem), which is only trustworthy when
     * this invoice is allocation-clean under Phase 1E's invariant — see
     * analyzeAllocations() for the exact rules an explicit split is
     * rejected outright without.
     *
     * @param  array<int, array{invoice_item_id: int, amount: string}>  $allocations
     */
    private function validateAllocations(array $allocations, Invoice $invoice, Collection $invoiceItems, string $amount): void
    {
        if ($allocations === []) {
            throw ValidationException::withMessages(['allocations' => 'Укажите распределение платежа по услугам.']);
        }

        $analysis = $this->analyzeAllocations($invoice, $invoiceItems, forUpdate: true);
        if (! $analysis['clean']) {
            // The invoice's true per-item remaining capacity is not
            // knowable (a historical/unattributed/partial payment or
            // refund, or an anomalous cross-reference, exists somewhere on
            // it). Never guess — fall back to Phase 1A's unallocated path
            // for this invoice.
            throw ValidationException::withMessages(['allocations' => 'По этому счёту нет однозначного распределения по строкам счёта, поэтому распределить новый платёж по строкам счёта нельзя.']);
        }
        $netByItem = $analysis['netByItem'];

        $validItemIds = $invoiceItems->pluck('id');
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
        // own NET remaining capacity (line amount minus net-of-refund
        // allocation) across all payments, historical plus this one.
        foreach ($lineAmountsByItem as $itemId => $newAmount) {
            $item = $itemsById->get($itemId);
            $netAllocated = $this->money((string) $netByItem->get($itemId, '0.00'));
            $itemRemaining = bcsub($this->money((string) $item->amount), $netAllocated, 2);
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
