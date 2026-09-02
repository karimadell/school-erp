<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasFactory;

    /**
     * Finance V2, Phase 2D corrective pass #2 (HIGH 4 — metadata
     * preservation). Every metadata key InvoiceIssuanceService itself
     * writes onto an item — pricing/coverage audit trace, quarterly
     * derivation, and yearly/quarterly/Food adjustment-basis fields.
     * A caller layering its OWN domain metadata on top of an already-
     * issued item (e.g. QuickStudentRegistrationService's Admissions-
     * domain enrichment) must always MERGE, protecting exactly these
     * keys, never plain-replace $item->metadata wholesale — a naive
     * replace silently destroys this entire audit trail the moment any
     * caller does its own post-issuance metadata write.
     */
    public const FINANCE_METADATA_KEYS = [
        'pricing_date', 'tariff_valid_from', 'tariff_valid_to', 'fee_price_id',
        'derived', 'derived_period', 'derived_from_period', 'derived_from_fee_price_id',
        'monthly_unit_amount', 'multiplier',
        'unit_tariff', 'billing_unit', 'unit_count', 'coverage_start', 'coverage_end', 'line_total',
        'partial_group_months', 'partial_group_unit_price', 'partial_group_amount', 'partial_group_start', 'partial_group_end',
        'adjustment_basis_period', 'adjustment_basis_fee_price_id', 'adjustment_basis_unit_amount', 'adjustment_basis_matched_dimensions',
        // Corrective pass #3 (HIGH 3 — quarterly <3-month/mixed-block line representation).
        'requested_billing_strategy', 'complete_quarterly_blocks', 'quarterly_package_applied', 'quarterly_package_price', 'blended_unit_price', 'per_block_amounts',
    ];

    protected $attributes = ['is_non_refundable' => false];

    /**
     * الحقول القابلة للـ mass assignment
     */
    protected $fillable = [
        'invoice_id',
        'fee_id',
        'subscription_id',
        'description',
        'amount',
        'unit_price',
        'quantity',
        'paid_amount',
        'remaining_amount',
        'is_non_refundable',
        'metadata',
    ];

    /**
     * تحويل نوع المبلغ
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'quantity' => 'integer',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'is_non_refundable' => 'boolean',
        'metadata' => 'array',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * الفاتورة التابعة لها
     */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * نوع الرسوم (مصروفات / زي / مطعم ...)
     */
    public function fee()
    {
        return $this->belongsTo(Fee::class);
    }

    public function subscription()
    {
        return $this->belongsTo(StudentServiceSubscription::class, 'subscription_id');
    }
}
