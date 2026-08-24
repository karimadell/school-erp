<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class TeacherSalary extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'teacher_id', 'employee_user_id', 'employee_name', 'position', 'base_salary',
        'bonus', 'allowances', 'deductions', 'net_salary', 'salary_month', 'status',
        'created_by', 'approved_by', 'approved_at', 'paid_by', 'paid_at', 'cash_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'salary_month' => 'date', 'approved_at' => 'datetime', 'paid_at' => 'datetime',
            'base_salary' => 'decimal:2', 'bonus' => 'decimal:2', 'allowances' => 'decimal:2',
            'deductions' => 'decimal:2', 'net_salary' => 'decimal:2',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }

    public function cashTransaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function getEmployeeDisplayNameAttribute(): string
    {
        return $this->employee_name ?: $this->employee?->name ?: $this->teacher?->full_name ?: '—';
    }

    public function calculateNet(): string
    {
        return bcsub(bcadd(bcadd((string) ($this->base_salary ?? 0), (string) ($this->bonus ?? 0), 2), (string) ($this->allowances ?? 0), 2), (string) ($this->deductions ?? 0), 2);
    }

    public function refreshTotals(): void
    {
        if (! $this->exists) {
            return;
        }
        $totals = $this->adjustments()->selectRaw('type, COALESCE(SUM(amount), 0) AS total')->groupBy('type')->pluck('total', 'type');
        $this->forceFill([
            'bonus' => $totals[PayrollAdjustment::TYPE_BONUS] ?? '0.00',
            'allowances' => $totals[PayrollAdjustment::TYPE_ALLOWANCE] ?? '0.00',
            'deductions' => $totals[PayrollAdjustment::TYPE_DEDUCTION] ?? '0.00',
        ]);
        $this->net_salary = $this->calculateNet();
        $this->saveQuietly();
    }

    protected static function booted(): void
    {
        static::creating(function (self $payroll): void {
            $payroll->status ??= self::STATUS_DRAFT;
            $payroll->employee_name ??= $payroll->employee?->name ?? $payroll->teacher?->full_name;
            $payroll->teacher_id ??= $payroll->employee?->teacher?->id;
            $payroll->net_salary = $payroll->calculateNet();
        });
        static::updating(function (self $payroll): void {
            if ($payroll->getOriginal('status') !== self::STATUS_DRAFT) {
                $allowed = ['status', 'approved_by', 'approved_at', 'paid_by', 'paid_at', 'cash_transaction_id', 'updated_at'];
                if (array_diff(array_keys($payroll->getDirty()), $allowed)) {
                    throw ValidationException::withMessages(['status' => __('teacher_salary.validation.locked')]);
                }
            }
            $payroll->net_salary = $payroll->calculateNet();
        });
        static::deleting(function (self $payroll): void {
            if ($payroll->status !== self::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => __('teacher_salary.validation.locked')]);
            }
        });
    }
}
