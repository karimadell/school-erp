<?php

namespace App\Models;

use App\Contracts\ResolvesAcademicYear;
use Illuminate\Database\Eloquent\Model;

class StudentServiceSubscription extends Model implements ResolvesAcademicYear
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'enrollment_id',
        'fee_id',
        'start_date',
        'end_date',
        'quantity',
        'status',
        'negotiated_price',
        'negotiated_reason',
        'negotiated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'quantity' => 'integer',
        'negotiated_price' => 'decimal:2',
    ];

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function resolveAcademicYear(): ?AcademicYear
    {
        return Enrollment::find($this->enrollment_id)?->resolveAcademicYear();
    }

    public function fee()
    {
        return $this->belongsTo(Fee::class);
    }

    public function negotiatedByUser()
    {
        return $this->belongsTo(User::class, 'negotiated_by');
    }

    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class, 'subscription_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function hasNegotiatedPrice(): bool
    {
        return ! is_null($this->negotiated_price);
    }

    /**
     * The price this subscription actually bills at: the negotiated
     * override if one was set, otherwise the Fee's current catalog price.
     * Never mutates or caches — always resolved fresh, so a mid-cycle
     * catalog price change is reflected for the next invoice generated,
     * without ever touching an already-issued InvoiceItem.
     */
    public function effectivePrice(): float
    {
        return $this->hasNegotiatedPrice()
            ? (float) $this->negotiated_price
            : $this->fee->currentPrice();
    }
}
