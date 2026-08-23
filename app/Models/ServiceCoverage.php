<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ServiceCoverage extends Model
{
    protected $fillable = [
        'student_id', 'fee_id', 'invoice_item_id', 'subscription_id', 'fee_price_id',
        'coverage_start', 'coverage_end', 'billing_unit', 'payment_period',
        'option_type', 'option_value', 'grade_group', 'item', 'size',
        'original_unit_price', 'metadata', 'created_by',
    ];

    protected $casts = [
        'coverage_start' => 'date', 'coverage_end' => 'date',
        'original_unit_price' => 'decimal:2', 'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Проведённое покрытие услуги нельзя изменить.'));
        static::deleting(fn () => throw new LogicException('Проведённое покрытие услуги нельзя удалить.'));
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function fee()
    {
        return $this->belongsTo(Fee::class);
    }

    public function invoiceItem()
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function subscription()
    {
        return $this->belongsTo(StudentServiceSubscription::class);
    }

    public function feePrice()
    {
        return $this->belongsTo(FeePrice::class);
    }

    public function adjustments()
    {
        return $this->hasMany(TariffAdjustment::class);
    }
}
