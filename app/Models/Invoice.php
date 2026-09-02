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
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Which flow issued this invoice. Optional and untracked (null) for
     * every flow except Quick Registration — see scopeVisibleInDocumentList().
     */
    public const ORIGIN_QUICK_REGISTRATION = 'quick_registration';

    protected $fillable = [
        'idempotency_key',
        'idempotency_hash',
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
        'origin',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
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
        'cancelled_at' => 'datetime',
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

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function refunds()
    {
        return $this->hasMany(PaymentRefund::class);
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

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** Total money refunded against this invoice (across all its payments). */
    public function refundedAmount(): string
    {
        return bcadd((string) $this->refunds()->sum('amount'), '0', 2);
    }

    /** Net money actually held: gross payments minus refunds. */
    public function netPaidAmount(): string
    {
        $gross = bcadd((string) $this->payments()->reorder()->sum('amount'), '0', 2);

        return bcsub($gross, $this->refundedAmount(), 2);
    }

    public function refreshPaymentStatus(): void
    {
        // A cancelled invoice is terminal: never resurrect it by recomputing
        // its status from payments/refunds.
        if ($this->isCancelled()) {
            return;
        }

        $net = bcadd((string) $this->total_amount, '0', 2);
        // Net paid reflects refunds so that outstanding debt increases again
        // when money is returned to the parent.
        $paid = $this->netPaidAmount();
        $remaining = bcsub($net, $paid, 2);

        $this->paid_amount = $paid;
        $this->remaining_amount = $remaining;

        if (bccomp($remaining, '0.00', 2) <= 0) {
            $this->status = self::STATUS_PAID;
            // A zero-total invoice is a waiver, not a cash event. Keep
            // paid_at null unless a genuine payment established it.
            if (bccomp($paid, '0.00', 2) > 0) {
                $this->paid_at ??= now();
            }
        } elseif (bccomp($paid, '0.00', 2) <= 0) {
            $this->status = self::STATUS_UNPAID;
            $this->paid_at = null;
        } elseif (bccomp($remaining, '0.00', 2) > 0) {
            $this->status = self::STATUS_PARTIAL;
            $this->paid_at = null;
        }

        $this->save();
    }

    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', [self::STATUS_UNPAID, self::STATUS_PARTIAL]);
    }

    /**
     * "Счета" list visibility rule (Phase 1, Quick Registration document
     * semantics): a Quick Registration invoice with nothing collected yet
     * is a genuine internal obligation, not something staff issued as a
     * document, so it's excluded from the default list. Every other
     * unpaid invoice — Classic Invoice, Mass Billing, Charge & Collect,
     * or any invoice with no recorded origin — is untouched by this
     * scope; it only ever excludes origin=quick_registration rows with
     * paid_amount exactly 0. Passing $includeUnpaidQuickRegistration=true
     * reveals them. The row itself remains reachable by direct route and
     * is always counted by Student Finance regardless of this scope.
     */
    public function scopeVisibleInDocumentList($query, bool $includeUnpaidQuickRegistration = false)
    {
        if ($includeUnpaidQuickRegistration) {
            return $query;
        }

        return $query->where(fn ($query) => $query
            ->where('origin', '!=', self::ORIGIN_QUICK_REGISTRATION)
            ->orWhereNull('origin')
            ->orWhere('paid_amount', '>', 0));
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
