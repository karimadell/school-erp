<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Finance V2, Phase 2D corrective pass #3 (P0 Blocker 1E). Which
 * InstallmentCoveragePeriod a slice of an item-level credit application
 * actually settles. Write-once and immutable.
 */
class CreditApplicationCoveragePeriod extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'student_credit_application_item_id',
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
                throw new LogicException('Распределение кредита по периоду покрытия нельзя изменить.');
            }
        });
        static::deleting(fn () => throw new LogicException('Распределение кредита по периоду покрытия нельзя удалить.'));
    }

    public function applicationItem()
    {
        return $this->belongsTo(StudentCreditApplicationItem::class, 'student_credit_application_item_id');
    }

    public function coveragePeriod()
    {
        return $this->belongsTo(InstallmentCoveragePeriod::class, 'installment_coverage_period_id');
    }
}
