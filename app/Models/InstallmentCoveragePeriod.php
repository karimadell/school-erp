<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Finance V2, Phase 2D (docs/finance-v2-architecture.md).
 *
 * Which calendar period a specific InvoiceInstallment represents —
 * deliberately independent of that installment's own due_date. Write-once
 * and immutable, matching PaymentAllocation's own guard: this is the same
 * class of auditable, never-rewritten financial-meaning record.
 */
class InstallmentCoveragePeriod extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'invoice_installment_id',
        'service_coverage_id',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $period): void {
            if ($period->exists) {
                throw new LogicException('Период покрытия этапа нельзя изменить.');
            }
        });
        static::deleting(fn () => throw new LogicException('Период покрытия этапа нельзя удалить.'));
    }

    public function installment()
    {
        return $this->belongsTo(InvoiceInstallment::class, 'invoice_installment_id');
    }

    public function coverage()
    {
        return $this->belongsTo(ServiceCoverage::class, 'service_coverage_id');
    }
}
