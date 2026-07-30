<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancePolicySetting extends Model
{
    protected $fillable = [
        'overdue_block_threshold_amount',
        'overdue_block_threshold_days',
    ];

    protected $casts = [
        'overdue_block_threshold_amount' => 'decimal:2',
        'overdue_block_threshold_days' => 'integer',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
