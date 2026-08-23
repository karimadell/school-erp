<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TariffAdjustmentSegment extends Model
{
    protected $fillable = [
        'tariff_adjustment_id', 'service_coverage_id', 'previous_fee_price_id', 'new_fee_price_id',
        'segment_start', 'segment_end', 'billing_unit', 'units', 'previous_unit_price',
        'new_unit_price', 'difference_per_unit', 'total_difference',
    ];

    protected $casts = [
        'segment_start' => 'date', 'segment_end' => 'date', 'units' => 'integer',
        'previous_unit_price' => 'decimal:2', 'new_unit_price' => 'decimal:2',
        'difference_per_unit' => 'decimal:2', 'total_difference' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Сегмент корректировки нельзя изменить.'));
        static::deleting(fn () => throw new LogicException('Сегмент корректировки нельзя удалить.'));
    }

    public function adjustment()
    {
        return $this->belongsTo(TariffAdjustment::class, 'tariff_adjustment_id');
    }
}
