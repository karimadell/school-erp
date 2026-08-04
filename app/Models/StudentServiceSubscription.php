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
    public const STATUS_ENDED = self::STATUS_COMPLETED;

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
        'metadata',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'quantity' => 'integer',
        'negotiated_price' => 'decimal:2',
        'metadata' => 'array',
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

    public function events()
    {
        return $this->hasMany(StudentServiceSubscriptionEvent::class, 'subscription_id')->latest('effective_date')->latest('id');
    }

    public function statusLabel(): string
    {
        return [self::STATUS_ACTIVE=>'Активна', self::STATUS_SUSPENDED=>'Приостановлена', self::STATUS_COMPLETED=>'Завершена', self::STATUS_CANCELLED=>'Завершена'][$this->status] ?? $this->status;
    }

    public function overlapsPeriod(string $startDate, ?string $endDate, ?int $exceptId = null): bool
    {
        return static::query()->where('enrollment_id', $this->enrollment_id)->where('fee_id', $this->fee_id)
            ->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_SUSPENDED])
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $endDate ?? '9999-12-31'))
            ->where(function ($query) use ($startDate) { $query->whereNull('end_date')->orWhereDate('end_date', '>=', $startDate); })
            ->exists();
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
