<?php

namespace App\Support;

use App\Models\InvoiceItem;
use Illuminate\Support\Carbon;

/**
 * Student Payment Allocation UX corrective — a short, decorative period
 * label ("01.09.2026 – 30.09.2026") for an InvoiceItem, read ONLY from
 * metadata InvoiceIssuanceService already writes onto the item at issuance
 * (coverage_start/coverage_end, or food_coverage_start/food_coverage_end for
 * Food lines — see InvoiceItem::FINANCE_METADATA_KEYS). No query, no new
 * billing/coverage logic, no accounting meaning: purely a display string,
 * safe to call from a paginated list without any N+1 risk. Returns null
 * when neither pair is present rather than guessing a period.
 */
final class InvoiceItemPeriodLabel
{
    public static function forItem(InvoiceItem $item): ?string
    {
        $metadata = $item->metadata ?? [];

        $start = $metadata['coverage_start'] ?? $metadata['food_coverage_start'] ?? null;
        $end = $metadata['coverage_end'] ?? $metadata['food_coverage_end'] ?? null;

        if (! $start || ! $end) {
            return null;
        }

        try {
            $start = Carbon::parse($start);
            $end = Carbon::parse($end);
        } catch (\Throwable) {
            return null;
        }

        return $start->format('d.m.Y').' – '.$end->format('d.m.Y');
    }
}
