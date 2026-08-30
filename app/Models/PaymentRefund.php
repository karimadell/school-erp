<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Immutable reversal record for a portion (or all) of a single InvoicePayment.
 * A refund never mutates or deletes the original payment; it stands alongside
 * it as explicit, auditable financial history.
 */
class PaymentRefund extends Model
{
    protected $fillable = [
        'refund_number',
        'invoice_payment_id',
        'invoice_id',
        'student_id',
        'invoice_installment_id',
        'cash_account_id',
        'cash_transaction_id',
        'amount',
        'currency',
        'reason',
        'refunded_at',
        'created_by',
        'idempotency_key',
        'idempotency_hash',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_at' => 'datetime',
    ];

    protected $attributes = [
        'currency' => 'EGP',
    ];

    protected static function booted(): void
    {
        static::created(function (self $refund): void {
            if ($refund->refund_number === null) {
                $refund->refund_number = self::numberFor($refund->id, $refund->created_at->format('Y'));
                $refund->saveQuietly();
            }
        });
        static::saving(function (self $refund): void {
            if ($refund->exists && $refund->getOriginal('refund_number') !== null) {
                foreach (['invoice_payment_id', 'invoice_id', 'cash_account_id', 'amount', 'refund_number'] as $field) {
                    if ($refund->isDirty($field)) {
                        throw new LogicException('Оформленный возврат нельзя изменить.');
                    }
                }
            }
        });
        static::deleting(fn () => throw new LogicException('Оформленный возврат нельзя удалить.'));
    }

    public static function numberFor(int $id, int|string $year): string
    {
        return sprintf('REF-%s-%06d', $year, $id);
    }

    public function getDisplayNumberAttribute(): string
    {
        return $this->refund_number ?? self::numberFor($this->id, $this->created_at?->format('Y') ?? '0000');
    }

    public function originalPayment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class, 'invoice_payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(InvoiceInstallment::class, 'invoice_installment_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function cashTransaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class);
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Finance V2, Phase 1D — which PaymentAllocation(s) this refund reversed. */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentRefundAllocation::class, 'payment_refund_id');
    }
}
