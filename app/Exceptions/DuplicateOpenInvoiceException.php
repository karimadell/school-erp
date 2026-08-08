<?php

namespace App\Exceptions;

use Exception;

/**
 * Phase 2 — Charge & Collect duplicate-open-invoice guard.
 *
 * Raised by ChargeAndCollectService when the same student already has a
 * collectible (unpaid / partially-paid) invoice for the same service in the
 * same academic year, so the cashier should collect against that invoice
 * instead of issuing a second one. Carries the existing invoice's id/number so
 * the controller can offer a direct link to it.
 */
class DuplicateOpenInvoiceException extends Exception
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $invoiceNumber,
        string $message,
    ) {
        parent::__construct($message);
    }
}
