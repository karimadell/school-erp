<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PayrollAdjustment extends Model
{
    public const TYPE_BONUS = 'bonus';

    public const TYPE_ALLOWANCE = 'allowance';

    public const TYPE_DEDUCTION = 'deduction';

    protected $fillable = ['teacher_salary_id', 'type', 'amount', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(TeacherSalary::class, 'teacher_salary_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $adjustment): void {
            if (! in_array($adjustment->type, [self::TYPE_BONUS, self::TYPE_ALLOWANCE, self::TYPE_DEDUCTION], true)) {
                throw ValidationException::withMessages(['type' => __('teacher_salary.validation.adjustment_type')]);
            }
            if (bccomp((string) $adjustment->amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => __('teacher_salary.validation.positive_amount')]);
            }
            if ($adjustment->exists && $adjustment->payroll()->where('status', '!=', TeacherSalary::STATUS_DRAFT)->exists()) {
                throw ValidationException::withMessages(['status' => __('teacher_salary.validation.locked')]);
            }
        });
        static::deleting(function (self $adjustment): void {
            if ($adjustment->payroll()->where('status', '!=', TeacherSalary::STATUS_DRAFT)->exists()) {
                throw ValidationException::withMessages(['status' => __('teacher_salary.validation.locked')]);
            }
        });
        static::saved(fn (self $adjustment) => $adjustment->payroll?->refreshTotals());
        static::deleted(fn (self $adjustment) => $adjustment->payroll?->refreshTotals());
    }
}
