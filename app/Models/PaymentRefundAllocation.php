<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Finance V2, Phase 1D (docs/finance-v2-architecture.md §19 Phase 1D).
 *
 * Which PaymentAllocation a slice of a confirmed PaymentRefund actually
 * reversed. Write-once and immutable — never updated or deleted once
 * created, mirroring PaymentAllocation's own guard, since this is the same
 * class of confirmed-money record.
 */
class PaymentRefundAllocation extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'payment_refund_id',
        'payment_allocation_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $allocation): void {
            if ($allocation->exists) {
                throw new LogicException('Распределение возврата нельзя изменить.');
            }
        });
        static::deleting(fn () => throw new LogicException('Распределение возврата нельзя удалить.'));
    }

    public function refund()
    {
        return $this->belongsTo(PaymentRefund::class, 'payment_refund_id');
    }

    public function allocation()
    {
        return $this->belongsTo(PaymentAllocation::class, 'payment_allocation_id');
    }
}
