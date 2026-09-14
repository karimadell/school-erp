<?php

namespace App\Support;

use App\Models\InvoiceItem;

/**
 * UAT display corrective pass — a "· "-joined summary of an InvoiceItem's
 * own curated metadata, for the generic per-line details column on the
 * invoice show/print views. Deliberately rejects ANY array- or
 * object-valued metadata entry (not just a specific known key): Collection::
 * implode() casts every remaining value to a string, and PHP's
 * array-to-string conversion is promoted to a thrown ErrorException during
 * Blade view rendering — this previously crashed both views with an HTTP
 * 500 for any invoice item whose metadata carries a non-scalar value (e.g.
 * Food's own food_tariff_segments, an array of tariff-price segments).
 * Scalar-safe generically, so a future metadata addition can never
 * reintroduce the same crash. No accounting meaning, no query, no change to
 * what metadata is written or persisted — display formatting only.
 */
final class InvoiceItemMetadataSummary
{
    private const EXCLUDED_KEYS = [
        'pricing_date', 'tariff_valid_from', 'tariff_valid_to', 'grade_id',
        'academic_year_id', 'currency', 'enrollment_mode_id', 'fee_price_id',
    ];

    public static function line(InvoiceItem $item): string
    {
        return collect($item->metadata ?? [])
            ->except(self::EXCLUDED_KEYS)
            ->reject(fn ($value) => is_array($value) || is_object($value))
            ->filter()
            ->implode(' · ');
    }
}
