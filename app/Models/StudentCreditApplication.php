<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class StudentCreditApplication extends Model
{
    protected $fillable = ['student_credit_id', 'student_id', 'invoice_id', 'amount', 'idempotency_key', 'applied_by', 'applied_at'];

    protected $casts = ['amount' => 'decimal:2', 'applied_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Применение кредита нельзя изменить.'));
        static::deleting(fn () => throw new LogicException('Применение кредита нельзя удалить.'));
    }

    public function credit()
    {
        return $this->belongsTo(StudentCredit::class, 'student_credit_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
