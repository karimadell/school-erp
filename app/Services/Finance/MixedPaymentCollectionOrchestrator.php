<?php

namespace App\Services\Finance;

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InstallmentCoveragePeriod;
use App\Models\User;
use App\Support\DeterministicIdempotencyKey;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Unified Collection foundation (PR A) — extracted, unchanged from
 * QuickStudentRegistrationService::register()'s own three payment-orchestration
 * branches (payment_type mixed / calendar+Food / plan-or-single-installment).
 * Each method here is a mechanical extraction of one branch's own body —
 * same InvoicePaymentService::record() calls, same allocation-splitting
 * math, same validation messages, same deterministic idempotency-key
 * derivation (now via DeterministicIdempotencyKey, formula unchanged — see
 * that class's own docblock) — never a rewrite. Every accounting decision
 * (which installment(s) a payment settles, how per-item allocations split
 * across shared calendar periods, when a payment is honestly recorded as
 * unallocated rather than guessed) still lives entirely inside
 * InvoicePaymentService::record() itself, unchanged; this class only
 * decides HOW MANY record() calls to make and WITH WHAT arguments, exactly
 * as QuickStudentRegistrationService always did inline.
 *
 * $keyNamespace lets a future caller (e.g. a Finance collection engine)
 * derive its own idempotency keys under a different namespace than
 * "quick-registration", so its retries can never collide with Quick
 * Registration's own keys — see DeterministicIdempotencyKey's own
 * docblock.
 */
class MixedPaymentCollectionOrchestrator
{
    public function __construct(private InvoicePaymentService $payments)
    {
    }

    /**
     * Once-bucket lump sum (if any) settled in one direct
     * installmentId+allocations call, then every calendar/Food period
     * across however many installments exist settled in one
     * coveragePeriodAllocations call — the exact split a 'mixed'
     * payment_type invoice always used.
     *
     * @param  array<int, array{invoice_item_id: int, amount: string}>  $allocations
     * @param  Collection<int, array<string, mixed>>  $normalizedServices  Same
     *         order as $orderedInvoiceItems — position N's selection
     *         belongs to item N.
     * @param  Collection<int, \App\Models\InvoiceItem>  $orderedInvoiceItems
     */
    public function collectMixed(
        Invoice $invoice,
        array $allocations,
        Collection $normalizedServices,
        Collection $orderedInvoiceItems,
        string $paidNow,
        string $paymentMethod,
        ?int $cashAccountId,
        User $actor,
        string $reference,
        ?string $notes,
        ?string $outerToken,
        string $keyNamespace,
    ): void {
        $remainingByItem = collect($allocations)->mapWithKeys(fn (array $line) => [
            (int) $line['invoice_item_id'] => (string) $line['amount'],
        ])->all();
        $onceItemIds = [];
        foreach ($orderedInvoiceItems as $position => $item) {
            $selection = $normalizedServices[$position];
            if (($selection['_fee_category'] ?? null) !== Fee::CATEGORY_FOOD
                && ($selection['_billing_strategy'] ?? 'once') === 'once') {
                $onceItemIds[] = $item->id;
            }
        }

        $onceAllocations = collect($onceItemIds)
            ->map(fn (int $id) => ['invoice_item_id' => $id, 'amount' => $remainingByItem[$id] ?? '0.00'])
            ->filter(fn (array $line) => bccomp($line['amount'], '0.00', 2) > 0)
            ->values()->all();
        $onceAmount = collect($onceAllocations)->reduce(fn (string $sum, array $line) => bcadd($sum, $line['amount'], 2), '0.00');
        if (bccomp($onceAmount, '0.00', 2) > 0) {
            $onceInstallment = $invoice->installments()->where('name_ru', InvoiceIssuanceService::MIXED_ONCE_INSTALLMENT_NAME)->firstOrFail();
            $this->payments->record(
                invoiceId: $invoice->id,
                cashAccountId: $cashAccountId,
                amount: $onceAmount,
                paymentMethod: $paymentMethod,
                idempotencyKey: DeterministicIdempotencyKey::derive($outerToken, $keyNamespace, 'mixed-once'),
                actor: $actor,
                reference: $reference,
                notes: $notes,
                installmentId: $onceInstallment->id,
                allocations: $onceAllocations,
            );
        }
        foreach ($onceItemIds as $id) {
            unset($remainingByItem[$id]);
        }

        $periodsAmount = bcsub($paidNow, $onceAmount, 2);
        if (bccomp($periodsAmount, '0.00', 2) > 0) {
            $periodAllocations = [];
            $periods = InstallmentCoveragePeriod::query()
                ->with(['coverage', 'installment'])
                ->whereHas('installment', fn ($query) => $query->where('invoice_id', $invoice->id))
                ->get()->sortBy(fn ($period) => sprintf('%08d|%08d', $period->installment->sequence, $period->coverage->invoice_item_id));
            foreach ($periods as $period) {
                $itemId = (int) $period->coverage->invoice_item_id;
                $remainingForItem = $remainingByItem[$itemId] ?? '0.00';
                if (bccomp($remainingForItem, '0.00', 2) <= 0) {
                    continue;
                }
                $portion = bccomp($remainingForItem, (string) $period->amount, 2) >= 0
                    ? (string) $period->amount
                    : $remainingForItem;
                $periodAllocations[] = [
                    'invoice_item_id' => $itemId,
                    'installment_coverage_period_id' => $period->id,
                    'amount' => $portion,
                ];
                $remainingByItem[$itemId] = bcsub($remainingForItem, $portion, 2);
            }
            if (collect($remainingByItem)->contains(fn ($remaining) => bccomp((string) $remaining, '0.00', 2) !== 0)) {
                throw ValidationException::withMessages(['services' => 'Оплату не удалось полностью распределить по выбранным периодам услуг.']);
            }
            $this->payments->record(
                invoiceId: $invoice->id,
                cashAccountId: $cashAccountId,
                amount: $periodsAmount,
                paymentMethod: $paymentMethod,
                idempotencyKey: DeterministicIdempotencyKey::derive($outerToken, $keyNamespace, 'mixed-periods'),
                actor: $actor,
                reference: $reference,
                notes: $notes,
                coveragePeriodAllocations: $periodAllocations,
            );
        }
    }

    /**
     * Every calendar period across however many installments exist,
     * settled in one coveragePeriodAllocations call — the exact behavior
     * the pre-existing 'calendar'+Food payment_type branch always used.
     *
     * @param  array<int, array{invoice_item_id: int, amount: string}>  $allocations
     */
    public function collectCalendarPeriods(
        Invoice $invoice,
        array $allocations,
        string $paidNow,
        string $paymentMethod,
        ?int $cashAccountId,
        User $actor,
        string $reference,
        ?string $notes,
        ?string $outerToken,
        string $keyNamespace,
    ): void {
        $remainingByItem = collect($allocations)->mapWithKeys(fn (array $line) => [
            (int) $line['invoice_item_id'] => (string) $line['amount'],
        ])->all();
        $periodAllocations = [];
        $periods = InstallmentCoveragePeriod::query()
            ->with(['coverage', 'installment'])
            ->whereHas('installment', fn ($query) => $query->where('invoice_id', $invoice->id))
            ->get()->sortBy(fn ($period) => sprintf('%08d|%08d', $period->installment->sequence, $period->coverage->invoice_item_id));
        foreach ($periods as $period) {
            $itemId = (int) $period->coverage->invoice_item_id;
            $remainingForItem = $remainingByItem[$itemId] ?? '0.00';
            if (bccomp($remainingForItem, '0.00', 2) <= 0) {
                continue;
            }
            $portion = bccomp($remainingForItem, (string) $period->amount, 2) >= 0
                ? (string) $period->amount
                : $remainingForItem;
            $periodAllocations[] = [
                'invoice_item_id' => $itemId,
                'installment_coverage_period_id' => $period->id,
                'amount' => $portion,
            ];
            $remainingByItem[$itemId] = bcsub($remainingForItem, $portion, 2);
        }
        if (collect($remainingByItem)->contains(fn ($remaining) => bccomp((string) $remaining, '0.00', 2) !== 0)) {
            throw ValidationException::withMessages(['services' => 'Оплату не удалось полностью распределить по выбранным периодам услуг.']);
        }
        $this->payments->record(
            invoiceId: $invoice->id,
            cashAccountId: $cashAccountId,
            amount: $paidNow,
            paymentMethod: $paymentMethod,
            idempotencyKey: DeterministicIdempotencyKey::derive($outerToken, $keyNamespace, 'calendar'),
            actor: $actor,
            reference: $reference,
            notes: $notes,
            coveragePeriodAllocations: $periodAllocations,
        );
    }

    /**
     * Walks the invoice's own installments in sequence, fully settling as
     * many as $paidNow covers — one record() call per settled installment,
     * with deterministic, per-installment-INDEX idempotency keys (not
     * invoice/installment id — see the original inline comment this
     * preserves: issue() always creates a brand new Invoice, so keying on
     * those ids would never actually collide on a retry; keying on the
     * stable (token, index) pair means a retry's record() calls reuse
     * attempt one's exact keys). Explicit per-item $allocations is only
     * ever passed when the whole payment settles in exactly one
     * installment (Phase 1A/1C's SUM(allocations) === payment.amount
     * invariant) — otherwise the payment is honestly recorded as
     * Unallocated, never guessed.
     *
     * @param  array<int, array{invoice_item_id: int, amount: string}>  $allocations
     */
    public function collectAcrossInstallments(
        Invoice $invoice,
        array $allocations,
        string $paidNow,
        string $paymentMethod,
        ?int $cashAccountId,
        User $actor,
        string $reference,
        ?string $notes,
        ?string $outerToken,
        string $keyNamespace,
    ): void {
        $installments = $invoice->installments()->orderBy('sequence')->get();
        if ($installments->isEmpty()) {
            throw ValidationException::withMessages(['services' => 'У счёта отсутствуют этапы оплаты.']);
        }

        $toRecord = [];

        if ($installments->count() === 1) {
            $installment = $installments->first();
            if (bccomp($paidNow, (string) $installment->remaining_amount, 2) > 0) {
                throw ValidationException::withMessages(['services' => 'Первоначальная оплата превышает сумму первого этапа рассрочки.']);
            }
            $toRecord[] = [$installment, $paidNow];
        } else {
            $remainingToApply = $paidNow;
            foreach ($installments as $installment) {
                if (bccomp($remainingToApply, '0.00', 2) <= 0) {
                    break;
                }
                $due = (string) $installment->remaining_amount;
                if (bccomp($remainingToApply, $due, 2) < 0) {
                    throw ValidationException::withMessages(['services' => 'Первоначальная оплата не покрывает целое число этапов оплаты.']);
                }
                $toRecord[] = [$installment, $due];
                $remainingToApply = bcsub($remainingToApply, $due, 2);
            }
            if (bccomp($remainingToApply, '0.00', 2) > 0) {
                throw ValidationException::withMessages(['services' => 'Первоначальная оплата превышает сумму счёта.']);
            }
        }

        foreach ($toRecord as $index => [$installment, $amount]) {
            $this->payments->record(
                invoiceId: $invoice->id,
                cashAccountId: $cashAccountId,
                amount: $amount,
                paymentMethod: $paymentMethod,
                idempotencyKey: DeterministicIdempotencyKey::derive($outerToken, $keyNamespace, (string) $index),
                actor: $actor,
                reference: $reference,
                notes: $notes,
                installmentId: $installment->id,
                allocations: (count($toRecord) === 1 && $index === 0) ? $allocations : null,
            );
        }
    }
}
