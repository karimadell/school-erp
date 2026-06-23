<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'student_id',
        'customer_name',
        'total_amount',
        'discount_type',
        'discount_value',
        'discount_amount',
        'paid_amount',
        'remaining_amount',
        'status',
        'payment_method',
        'cash_account_id',
        'paid_at',
        'note',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function fees()
    {
        return $this->belongsToMany(Fee::class, 'invoice_fee')
            ->withPivot([
                'amount',
                'item',
                'size',
                'option_type',
                'option_value',
            ])
            ->withTimestamps();
    }

    public function payments()
    {
        return $this->hasMany(InvoicePayment::class)->latest();
    }

    public function refreshPaymentStatus(): void
    {
        $total = (float) $this->total_amount;
        $discount = (float) ($this->discount_amount ?? 0);
        $net = max($total - $discount, 0);

        $paid = min((float) $this->paid_amount, $net);
        $remaining = max($net - $paid, 0);

        $this->paid_amount = $paid;
        $this->remaining_amount = $remaining;

        if ($paid <= 0) {
            $this->status = self::STATUS_UNPAID;
            $this->paid_at = null;
        } elseif ($remaining > 0) {
            $this->status = self::STATUS_PARTIAL;
            $this->paid_at ??= now();
        } else {
            $this->status = self::STATUS_PAID;
            $this->paid_at ??= now();
        }

        $this->save();
    }

    public function getNetAmountAttribute(): float
    {
        return max(
            (float) $this->total_amount - (float) ($this->discount_amount ?? 0),
            0
        );
    }
}