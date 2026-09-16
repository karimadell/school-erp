<?php

namespace App\Support;

use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Unified Collection foundation (PR A) — extracted, unchanged formula.
 *
 * QuickStudentRegistrationService already derives deterministic per-call
 * child idempotency keys from one outer, page-render-stable token, so a
 * retried submission reuses the exact same InvoicePaymentService::record()
 * keys instead of generating a fresh random UUID per attempt (see that
 * class's own submissionPaidAmount()/register() docblocks for the full
 * rationale). This is the exact same derivation, made reusable: given the
 * same $outerToken, $namespace and $suffix, derive() returns byte-identical
 * output to what QuickStudentRegistrationService always computed inline —
 * verified by a direct characterization test, not merely assumed.
 *
 * $namespace exists so a future caller (e.g. a Finance collection engine)
 * can derive its own keys under a DIFFERENT namespace string, guaranteeing
 * no collision with Quick Registration's own "quick-registration:..." keys
 * — UUID v5 is a hash of the whole input string, so a different namespace
 * prefix always produces a different key space.
 */
final class DeterministicIdempotencyKey
{
    public static function derive(?string $outerToken, string $namespace, string $suffix): string
    {
        return $outerToken !== null
            ? (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "{$namespace}:{$outerToken}:{$suffix}")
            : (string) Str::uuid();
    }
}
