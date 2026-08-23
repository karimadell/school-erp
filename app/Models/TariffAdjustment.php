<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TariffAdjustment extends Model
{
    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'student_id', 'fee_id', 'service_coverage_id', 'previous_fee_price_id',
        'new_fee_price_id', 'status', 'kind', 'total_difference', 'currency',
        'posting_invoice_id', 'approved_by', 'approved_at', 'note',
    ];

    protected $casts = ['total_difference' => 'decimal:2', 'approved_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Проведённую корректировку нельзя изменить.'));
        static::deleting(fn () => throw new LogicException('Проведённую корректировку нельзя удалить.'));
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function fee()
    {
        return $this->belongsTo(Fee::class);
    }

    public function coverage()
    {
        return $this->belongsTo(ServiceCoverage::class, 'service_coverage_id');
    }

    public function previousPrice()
    {
        return $this->belongsTo(FeePrice::class, 'previous_fee_price_id');
    }

    public function newPrice()
    {
        return $this->belongsTo(FeePrice::class, 'new_fee_price_id');
    }

    public function postingInvoice()
    {
        return $this->belongsTo(Invoice::class, 'posting_invoice_id');
    }

    public function segments()
    {
        return $this->hasMany(TariffAdjustmentSegment::class);
    }

    public function getKindAttribute(): string
    {
        return $this->attributes['kind'] ?? (bccomp((string) $this->total_difference, '0.00', 2) >= 0 ? 'debit' : 'credit');
    }
}
