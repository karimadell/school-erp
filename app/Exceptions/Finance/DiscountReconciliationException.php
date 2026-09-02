<?php

namespace App\Exceptions\Finance;

use RuntimeException;

/**
 * Corrective pass #4 (HIGH 1). Thrown when InvoiceIssuanceService::issue()'s
 * post-discount hard invariant checks find a mismatch between the invoice
 * total, an item's own final discounted amount, its installment/coverage
 * period amounts, or the installment schedule total. Always thrown inside
 * the issuance DB::transaction() closure so it rolls the whole issuance
 * back — never a silent partial commit. This should never fire "in the
 * wild" (the reconciliation is correct by construction); it exists as a
 * fail-loud safety net, not an expected user-facing error path.
 */
class DiscountReconciliationException extends RuntimeException
{
}
