<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PromiseToPay extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'student_id', 'invoice_id', 'promised_amount', 'expected_payment_date', 'note',
        'status', 'created_by', 'fulfilled_at', 'fulfilled_by', 'invoice_payment_id',
        'cancelled_at', 'cancelled_by', 'cancellation_note',
    ];

    protected $casts = [
        'promised_amount' => 'decimal:2', 'expected_payment_date' => 'date',
        'fulfilled_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $promise): void {
            if (bccomp((string) $promise->promised_amount, '0.00', 2) <= 0 || ! $promise->expected_payment_date) {
                throw new LogicException('Обещание должно иметь положительную сумму и ожидаемую дату.');
            }
            $valid = match ($promise->status) {
                self::STATUS_OPEN => ! $promise->fulfilled_at && ! $promise->fulfilled_by && ! $promise->invoice_payment_id && ! $promise->cancelled_at && ! $promise->cancelled_by,
                self::STATUS_FULFILLED => $promise->fulfilled_at && $promise->fulfilled_by && $promise->invoice_payment_id && ! $promise->cancelled_at && ! $promise->cancelled_by,
                self::STATUS_CANCELLED => $promise->cancelled_at && ! $promise->fulfilled_at && ! $promise->fulfilled_by && ! $promise->invoice_payment_id,
                default => false,
            };
            if (! $valid) {
                throw new LogicException('Поля состояния обещания оплаты несогласованы.');
            }
        });
        static::updating(function (self $promise): void {
            foreach (['student_id', 'invoice_id', 'promised_amount', 'expected_payment_date', 'note', 'created_by', 'created_at'] as $field) {
                if ($promise->isDirty($field)) {
                    throw new LogicException('Исходные условия обещания оплаты нельзя изменить.');
                }
            }
            $allowed = ['status', 'fulfilled_at', 'fulfilled_by', 'invoice_payment_id', 'cancelled_at', 'cancelled_by', 'cancellation_note', 'updated_at'];
            if (array_diff(array_keys($promise->getDirty()), $allowed)) {
                throw new LogicException('Изменение обещания разрешено только через переход состояния.');
            }
        });
        static::deleting(fn () => throw new LogicException('Обещание оплаты нельзя удалить.'));
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment()
    {
        return $this->belongsTo(InvoicePayment::class, 'invoice_payment_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === self::STATUS_OPEN && $this->expected_payment_date->isPast();
    }
}
