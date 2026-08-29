<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Finance V2, Phase 1A (docs/finance-v2-architecture.md §7).
 *
 * Which InvoiceItem a slice of a confirmed InvoicePayment actually paid
 * down. Write-once and immutable — never updated or deleted once created,
 * mirroring InvoicePayment's own guard, since this is the same class of
 * confirmed-money record.
 */
class PaymentAllocation extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'invoice_payment_id',
        'invoice_item_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $allocation): void {
            if ($allocation->exists) {
                throw new LogicException('Распределение платежа нельзя изменить.');
            }
        });
        static::deleting(fn () => throw new LogicException('Распределение платежа нельзя удалить.'));
    }

    public function payment()
    {
        return $this->belongsTo(InvoicePayment::class, 'invoice_payment_id');
    }

    public function item()
    {
        return $this->belongsTo(InvoiceItem::class, 'invoice_item_id');
    }
}
