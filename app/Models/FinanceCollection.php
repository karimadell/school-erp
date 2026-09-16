<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unified Collection foundation (PR B). ONE FinanceCollection is ONE
 * parent-facing collection operation — exactly one Student, exactly one
 * AcademicYear, exactly one payment method, at most one cash account —
 * grouping whatever InvoicePayment rows (and, when new charges were
 * created, Invoice rows) FinanceCollectionService recorded inside its own
 * outer transaction. See FinanceCollectionService's own docblock for the
 * orchestration this model is a passive record of; this class itself owns
 * no accounting logic.
 */
class FinanceCollection extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    /**
     * Defined for schema completeness (see this table's migration
     * docblock) but never written by FinanceCollectionService's own
     * atomic path today — a failure rolls the whole outer transaction
     * back, so the row itself never persists at all, exactly like
     * QuickRegistrationOperation already guarantees for the same reason.
     * Reserved for a future explicit-cancellation flow, should one ever
     * need to mark a collection failed after the fact rather than simply
     * never having created it.
     */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'idempotency_key',
        'payload_hash',
        'collection_number',
        'student_id',
        'academic_year_id',
        'created_by',
        'payment_method',
        'cash_account_id',
        'notes',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (self $collection): void {
            if ($collection->collection_number === null) {
                $collection->collection_number = self::numberFor($collection->id, $collection->created_at->format('Y'));
                $collection->saveQuietly();
            }
        });
    }

    public static function numberFor(int $id, int|string $year): string
    {
        return sprintf('RCPT-%s-%06d', $year, $id);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    /** Invoices touched by this collection — pre-existing obligations paid and/or newly issued charges. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function invoicePayments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    /**
     * §10 of the approved design — the receipt backend read model.
     * invoices() (finance_collection_id) only ever covers an invoice this
     * collection itself ISSUED (a new charge, via
     * FinanceCollectionService::collectNewServices()) — an EXISTING
     * obligation's invoice was issued by whatever OTHER flow created it
     * long before this collection merely paid it down, so setting its own
     * finance_collection_id would misrepresent who actually issued it.
     * This accessor is the honest "every invoice this collection touched
     * at all" view: invoices it issued, unioned with invoices it recorded
     * a payment against (via invoicePayments()->invoice), deduplicated.
     *
     * @return \Illuminate\Support\Collection<int, Invoice>
     */
    public function linkedInvoices(): \Illuminate\Support\Collection
    {
        return Invoice::query()
            ->where('finance_collection_id', $this->id)
            ->orWhereHas('payments', fn ($query) => $query->where('finance_collection_id', $this->id))
            ->with('items')
            ->get();
    }

    /**
     * The GROSS "how much was actually received" number for this
     * collection, historical-receipt semantics — always derived from
     * SUM(linked InvoicePayment amounts), never stored redundantly on
     * this row (see this table's migration docblock for why). A linked
     * payment's own refunds are NOT subtracted: this is what the
     * collection's receipt originally recorded as received, not a
     * live "currently held" balance. InvoiceRefundService (untouched by
     * this feature) is the canonical source for refund amounts, should a
     * future net-of-refunds view ever be needed — this method
     * deliberately does not attempt to compute one, mirroring
     * Invoice::netPaidAmount() being a distinct, separate accessor from
     * a raw payment sum rather than folding refund-netting into this one.
     *
     * Corrective pass (P2, receipt-semantics ambiguity) — named explicitly
     * so a future receipt UI cannot mistake this for a live held balance.
     */
    public function grossReceivedTotal(): string
    {
        return bcadd((string) $this->invoicePayments()->sum('amount'), '0', 2);
    }

    /**
     * @deprecated Kept only for call-site compatibility with earlier PR B
     *             usage — always delegates to grossReceivedTotal(). Prefer
     *             that name directly; it makes the gross-not-net semantics
     *             explicit at every call site instead of relying on this
     *             method's own docblock being read.
     */
    public function receivedTotal(): string
    {
        return $this->grossReceivedTotal();
    }
}
