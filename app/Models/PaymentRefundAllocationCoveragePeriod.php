<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Finance V2, Phase 2D corrective pass #3 (P0 Blocker 1D). Which
 * InstallmentCoveragePeriod a slice of a confirmed refund actually
 * reverses. Write-once and immutable — same class of confirmed-money
 * record as every other Finance V2 allocation-chain table.
 */
class PaymentRefundAllocationCoveragePeriod extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'payment_refund_allocation_id',
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
                throw new LogicException('Распределение возврата по периоду покрытия нельзя изменить.');
            }
        });
        static::deleting(fn () => throw new LogicException('Распределение возврата по периоду покрытия нельзя удалить.'));
    }

    public function refundAllocation()
    {
        return $this->belongsTo(PaymentRefundAllocation::class, 'payment_refund_allocation_id');
    }

    public function coveragePeriod()
    {
        return $this->belongsTo(InstallmentCoveragePeriod::class, 'installment_coverage_period_id');
    }
}
