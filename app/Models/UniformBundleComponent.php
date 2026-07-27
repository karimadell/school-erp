<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Links a uniform bundle (a Fee) to the individual items (also Fee rows)
 * that make it up. Fulfillment/packing metadata only — pricing is never
 * derived from this table (policy decision 1: a bundle has its own price).
 */
class UniformBundleComponent extends Model
{
    protected $fillable = [
        'bundle_fee_id',
        'item_fee_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function bundle()
    {
        return $this->belongsTo(Fee::class, 'bundle_fee_id');
    }

    public function item()
    {
        return $this->belongsTo(Fee::class, 'item_fee_id');
    }
}
