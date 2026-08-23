<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class StudentCredit extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_PARTIAL = 'partially_consumed';

    public const STATUS_CONSUMED = 'consumed';

    protected $fillable = ['student_id', 'source_adjustment_id', 'original_amount', 'consumed_amount', 'available_amount', 'status'];

    protected $casts = ['original_amount' => 'decimal:2', 'consumed_amount' => 'decimal:2', 'available_amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::updating(function (self $credit): void {
            foreach (['student_id', 'source_adjustment_id', 'original_amount', 'created_at'] as $field) {
                if ($credit->isDirty($field)) {
                    throw new LogicException('Исходные данные кредита нельзя изменить.');
                }
            }
        });
        static::deleting(fn () => throw new LogicException('Историю кредита нельзя удалить.'));
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function sourceAdjustment()
    {
        return $this->belongsTo(TariffAdjustment::class, 'source_adjustment_id');
    }

    public function applications()
    {
        return $this->hasMany(StudentCreditApplication::class);
    }
}
