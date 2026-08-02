<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class InvoicePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'cash_account_id',
        'amount',
        'payment_method',
        'paid_at',
        'reference',
        'notes',
        'created_by',
        'payment_number',
        'idempotency_key',
        'idempotency_hash',
    ];

    protected static function booted(): void
    {
        static::created(function (self $payment): void {
            if ($payment->payment_number === null) {
                $payment->payment_number = self::numberFor($payment->id, $payment->created_at->format('Y'));
                $payment->saveQuietly();
            }
        });
        static::saving(function (self $payment): void {
            if ($payment->exists && $payment->getOriginal('payment_number') !== null) {
                foreach (['invoice_id', 'cash_account_id', 'amount', 'payment_number'] as $field) {
                    if ($payment->isDirty($field)) {
                        throw new LogicException('Проведённый платёж нельзя изменить.');
                    }
                }
            }
        });
        static::deleting(fn () => throw new LogicException('Проведённый платёж нельзя удалить.'));
    }

    public static function numberFor(int $id, int|string $year): string
    {
        return sprintf('PAY-%s-%06d', $year, $id);
    }

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cashTransaction()
    {
        return $this->hasOne(CashTransaction::class);
    }
}
