<?php

namespace App\Services\Finance;

/**
 * Finance V2, Phase 2A (docs/finance-v2-architecture.md).
 *
 * Per-row service-attribution classification for the read-only Collections
 * page — never used to alter write-time behavior. See
 * PaymentAllocationAnalyzer::classifyPayment()/classifyRefund() for the
 * exact rules each case corresponds to.
 */
enum PaymentAllocationStatus: string
{
    /** Every unit of this payment's/refund's amount is attributed to a specific InvoiceItem, with no anomaly. */
    case FullyAttributed = 'fully_attributed';

    /** Zero attribution coverage — grandfathered historical compatibility state. Never guessed, never backfilled. */
    case Unallocated = 'unallocated';

    /** Partial coverage, a cross-payment/cross-refund reference, an excess-refund, or another Phase 1E invariant violation. */
    case NeedsReview = 'needs_review';
}
