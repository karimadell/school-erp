<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Finance V2, Phase 2D corrective pass #2 (P0 Blocker 2).
 *
 * Which InstallmentCoveragePeriod a PaymentAllocation's amount actually
 * settles — the one level of precision PaymentAllocation alone can't
 * express when its own InvoiceItem spans multiple coverage periods (e.g.
 * Transport's item covering 9 months). Write-once and immutable, same
 * class of confirmed-money record as PaymentAllocation/
 * InstallmentCoveragePeriod themselves.
 */
class PaymentAllocationCoveragePeriod extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'payment_allocation_id',
        'installment_coverage_period_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            if ($row->exists) {
                throw new LogicException('Распределение платежа по периоду покрытия нельзя изменить.');
            }
        });
        static::deleting(fn () => throw new LogicException('Распределение платежа по периоду покрытия нельзя удалить.'));
    }

    public function allocation()
    {
        return $this->belongsTo(PaymentAllocation::class, 'payment_allocation_id');
    }

    public function coveragePeriod()
    {
        return $this->belongsTo(InstallmentCoveragePeriod::class, 'installment_coverage_period_id');
    }
}
