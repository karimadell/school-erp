<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Finance V2, Phase 2D corrective pass #3 (P0 Blocker 1E). Which
 * InvoiceItem(s) a StudentCreditApplication actually reduced — optional,
 * additive item-level attribution for an otherwise invoice-level credit
 * application. Write-once and immutable.
 */
class StudentCreditApplicationItem extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'student_credit_application_id',
        'invoice_item_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            if ($row->exists) {
                throw new LogicException('Распределение применения кредита нельзя изменить.');
            }
        });
        static::deleting(fn () => throw new LogicException('Распределение применения кредита нельзя удалить.'));
    }

    public function application()
    {
        return $this->belongsTo(StudentCreditApplication::class, 'student_credit_application_id');
    }

    public function item()
    {
        return $this->belongsTo(InvoiceItem::class, 'invoice_item_id');
    }

    public function coveragePeriods()
    {
        return $this->hasMany(CreditApplicationCoveragePeriod::class, 'student_credit_application_item_id');
    }
}
