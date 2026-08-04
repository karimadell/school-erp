<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Invoice extends Model
{
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'student_id',
        'invoice_number',
        'currency',
        'academic_year_id',
        'customer_name',
        'subtotal_amount',
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
        'due_date',
        'note',
        'created_by',
    ];

    protected $casts = [
        'subtotal_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'due_date' => 'date',
    ];

    protected $attributes = [
        'currency' => 'EGP',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $invoice) {
            if (
                $invoice->isDirty('invoice_number')
                && $invoice->getOriginal('invoice_number') !== null
                && $invoice->invoice_number !== $invoice->getOriginal('invoice_number')
            ) {
                throw new LogicException('Номер счёта нельзя изменить после присвоения.');
            }
        });
    }

    public static function numberFor(int $id, int|string $year): string
    {
        return sprintf('INV-%s-%06d', $year, $id);
    }

    public function getDisplayNumberAttribute(): string
    {
        return $this->invoice_number
            ?? self::numberFor($this->id, $this->created_at?->format('Y') ?? '0000');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function installments()
    {
        return $this->hasMany(InvoiceInstallment::class)->orderBy('sequence');
    }

    public function refreshPaymentStatus(): void
    {
        $net = bcadd((string) $this->total_amount, '0', 2);
        $paid = bcadd((string) $this->payments()->reorder()->sum('amount'), '0', 2);
        $remaining = bcsub($net, $paid, 2);

        $this->paid_amount = $paid;
        $this->remaining_amount = $remaining;

        if (bccomp($paid, '0.00', 2) <= 0) {
            $this->status = self::STATUS_UNPAID;
            $this->paid_at = null;
        } elseif (bccomp($remaining, '0.00', 2) > 0) {
            $this->status = self::STATUS_PARTIAL;
            $this->paid_at = null;
        } else {
            $this->status = self::STATUS_PAID;
            $this->paid_at ??= now();
        }

        $this->save();
    }

    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', [self::STATUS_UNPAID, self::STATUS_PARTIAL]);
    }

    public function scopeOverdue($query, $asOf = null)
    {
        $asOf = $asOf ?? now()->toDateString();

        return $query->outstanding()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $asOf);
    }

    public function getNetAmountAttribute(): float
    {
        return max((float) $this->total_amount, 0);
    }
}
