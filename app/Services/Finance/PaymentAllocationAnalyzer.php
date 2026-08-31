<?php

namespace App\Services\Finance;

use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\PaymentRefundAllocation;
use Illuminate\Support\Collection;

/**
 * Finance V2, Phase 2A extraction (docs/finance-v2-architecture.md).
 *
 * The PURE, stateless arithmetic/classification core of what was
 * InvoicePaymentService::analyzeAllocations(). No DB queries, no
 * lockForUpdate(), no writes — every method here takes already-fetched
 * Eloquent collections and returns a deterministic result.
 *
 * InvoicePaymentService retains sole responsibility for the transaction,
 * fetching, locking (and lock ORDER — see its own analyzeAllocations()
 * docblock for the full deadlock-avoidance proof against
 * InvoiceRefundService's reverse lock order; this extraction moves none of
 * that querying/locking code, only the final computation it used to do
 * inline), idempotency, validation, and persistence.
 *
 * A second, read-only caller (the Finance V2 Phase 2A Collections page) uses
 * the per-payment/per-refund classification methods below against a plain,
 * unlocked, eager-loaded read — safe by construction, since this class never
 * touches the database itself. See classifyPayment()/classifyRefund().
 */
class PaymentAllocationAnalyzer
{
    /**
     * Exact extraction of the invoice-wide clean/ambiguous verdict and net
     * per-item figure InvoicePaymentService::analyzeAllocations() used to
     * compute inline — same six rules, same semantics, behavior-preserving.
     * See that method's own (still-present) docblock for the full
     * definition of "allocation-clean"; not duplicated here.
     *
     * @param  Collection<int, InvoiceItem>  $invoiceItems
     * @param  Collection<int, InvoicePayment>  $payments  every InvoicePayment on the invoice
     * @param  Collection<int, PaymentAllocation>  $allocations  every PaymentAllocation scoped to $invoiceItems
     * @param  Collection<int, PaymentRefund>  $refunds  every PaymentRefund on the invoice, with 'allocations' eager-loaded
     * @return array{clean: bool, netByItem: Collection<int, string>}
     */
    public function analyzeInvoice(Collection $invoiceItems, Collection $payments, Collection $allocations, Collection $refunds): array
    {
        $allocationsById = $allocations->keyBy('id');
        $allocationsByPayment = $allocations->groupBy('invoice_payment_id');

        $clean = true;

        foreach ($payments as $payment) {
            if (bccomp((string) $payment->amount, '0.00', 2) <= 0) {
                // Legacy negative/zero-amount row — record() never produces
                // one. Phase 1E does not redesign legacy refund history;
                // conservatively ambiguous.
                $clean = false;

                continue;
            }
            $coverage = $this->money((string) $allocationsByPayment->get($payment->id, collect())->reduce(
                fn (string $carry, $a) => bcadd($carry, (string) $a->amount, 2), '0.00'
            ));
            if (bccomp($coverage, $this->money((string) $payment->amount), 2) !== 0) {
                // FULL coverage is required to be clean. ZERO coverage
                // (grandfathered historical data) and PARTIAL coverage
                // (anomalous) both poison the invoice.
                $clean = false;
            }
        }

        $refundedByAllocation = [];
        foreach ($refunds as $refund) {
            if (bccomp((string) $refund->amount, '0.00', 2) <= 0) {
                $clean = false;

                continue;
            }
            $coverage = $this->money((string) $refund->allocations->reduce(
                fn (string $carry, $a) => bcadd($carry, (string) $a->amount, 2), '0.00'
            ));
            if (bccomp($coverage, $this->money((string) $refund->amount), 2) !== 0) {
                $clean = false;

                continue;
            }
            foreach ($refund->allocations as $refundAllocation) {
                $allocation = $allocationsById->get($refundAllocation->payment_allocation_id);
                if (! $allocation || $allocation->invoice_payment_id !== $refund->invoice_payment_id) {
                    // Cross-payment (or dangling / cross-invoice-item)
                    // refund allocation.
                    $clean = false;

                    continue;
                }
                if (bccomp((string) $refundAllocation->amount, '0.00', 2) <= 0) {
                    $clean = false;

                    continue;
                }
                $refundedByAllocation[$refundAllocation->payment_allocation_id] = bcadd(
                    $refundedByAllocation[$refundAllocation->payment_allocation_id] ?? '0.00',
                    $this->money((string) $refundAllocation->amount),
                    2
                );
            }
        }

        $netByItem = $invoiceItems->mapWithKeys(fn ($item) => [$item->id => '0.00']);
        foreach ($allocationsById as $allocation) {
            $refunded = $this->money($refundedByAllocation[$allocation->id] ?? '0.00');
            if (bccomp($refunded, (string) $allocation->amount, 2) > 0) {
                $clean = false;
            }
            $net = bcsub((string) $allocation->amount, $refunded, 2);
            $netByItem[$allocation->invoice_item_id] = bcadd($netByItem[$allocation->invoice_item_id], $net, 2);
        }

        foreach ($invoiceItems as $item) {
            $net = $netByItem[$item->id];
            if (bccomp($net, '0.00', 2) < 0 || bccomp($net, $this->money((string) $item->amount), 2) > 0) {
                $clean = false;
            }
        }

        return ['clean' => $clean, 'netByItem' => $netByItem];
    }

    /**
     * Finance V2, Phase 2A — per-payment service-attribution status for the
     * read-only Collections page. Independent of any OTHER payment/refund
     * elsewhere on the same invoice: a payment is judged solely on its own
     * amount, its own PaymentAllocation coverage, and the
     * PaymentRefundAllocation rows that reference its own allocations. A
     * fully-attributed refund against a clean payment does NOT make the
     * payment ambiguous — refund coverage only matters here insofar as it
     * must never exceed what was ever allocated to this payment's own
     * allocations (rule 5 of analyzeInvoice()'s invariant, scoped down to
     * this one payment).
     *
     * @param  Collection<int, PaymentAllocation>  $allocations  this payment's own PaymentAllocation rows
     * @param  Collection<int, PaymentRefundAllocation>  $refundAllocationsAgainstOwnAllocations  every PaymentRefundAllocation referencing any allocation in $allocations, from any refund
     */
    public function classifyPayment(InvoicePayment $payment, Collection $allocations, Collection $refundAllocationsAgainstOwnAllocations): PaymentAllocationStatus
    {
        if (bccomp((string) $payment->amount, '0.00', 2) <= 0) {
            // Legacy negative/zero-amount row.
            return PaymentAllocationStatus::NeedsReview;
        }

        $coverage = $this->money($allocations->reduce(fn (string $carry, $a) => bcadd($carry, (string) $a->amount, 2), '0.00'));

        if (bccomp($coverage, '0.00', 2) === 0) {
            return PaymentAllocationStatus::Unallocated;
        }

        if (bccomp($coverage, $this->money((string) $payment->amount), 2) !== 0) {
            // Neither zero nor full — anomalous.
            return PaymentAllocationStatus::NeedsReview;
        }

        // Fully covered — still check the refund side of THIS payment's own
        // allocations for the excess-refund invariant (rule 5): cumulative
        // refunds against any one allocation may never exceed that
        // allocation's own amount.
        $refundedByAllocation = $refundAllocationsAgainstOwnAllocations
            ->groupBy('payment_allocation_id')
            ->map(fn (Collection $rows) => $this->money($rows->reduce(fn (string $carry, $r) => bcadd($carry, (string) $r->amount, 2), '0.00')));

        foreach ($allocations as $allocation) {
            $refunded = $refundedByAllocation->get($allocation->id, '0.00');
            if (bccomp($refunded, (string) $allocation->amount, 2) > 0) {
                return PaymentAllocationStatus::NeedsReview;
            }
        }

        return PaymentAllocationStatus::FullyAttributed;
    }

    /**
     * Finance V2, Phase 2A — per-refund service-attribution status. A
     * refund is judged on its own amount, its own PaymentRefundAllocation
     * coverage, and whether every one of its allocation lines correctly
     * references an allocation belonging to the SAME InvoicePayment this
     * refund refunds (rule 4 of analyzeInvoice()'s invariant, scoped to
     * this one refund).
     *
     * @param  Collection<int, PaymentRefundAllocation>  $refundAllocations  this refund's own rows
     * @param  Collection<int, PaymentAllocation>  $paymentAllocationsById  the refunded payment's own PaymentAllocation rows, keyed by id — used only to verify each reference belongs to this same payment
     */
    public function classifyRefund(PaymentRefund $refund, Collection $refundAllocations, Collection $paymentAllocationsById): PaymentAllocationStatus
    {
        if (bccomp((string) $refund->amount, '0.00', 2) <= 0) {
            return PaymentAllocationStatus::NeedsReview;
        }

        $coverage = $this->money($refundAllocations->reduce(fn (string $carry, $a) => bcadd($carry, (string) $a->amount, 2), '0.00'));

        if (bccomp($coverage, '0.00', 2) === 0) {
            return PaymentAllocationStatus::Unallocated;
        }

        if (bccomp($coverage, $this->money((string) $refund->amount), 2) !== 0) {
            return PaymentAllocationStatus::NeedsReview;
        }

        foreach ($refundAllocations as $refundAllocation) {
            $allocation = $paymentAllocationsById->get($refundAllocation->payment_allocation_id);
            if (! $allocation || $allocation->invoice_payment_id !== $refund->invoice_payment_id) {
                // Cross-payment (or dangling) reference.
                return PaymentAllocationStatus::NeedsReview;
            }
            if (bccomp((string) $refundAllocation->amount, '0.00', 2) <= 0) {
                return PaymentAllocationStatus::NeedsReview;
            }
        }

        return PaymentAllocationStatus::FullyAttributed;
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }
}
