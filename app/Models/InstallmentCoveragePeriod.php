<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Finance V2, Phase 2D (docs/finance-v2-architecture.md).
 *
 * Which calendar period a specific InvoiceInstallment represents —
 * deliberately independent of that installment's own due_date. Write-once
 * and immutable, matching PaymentAllocation's own guard: this is the same
 * class of auditable, never-rewritten financial-meaning record.
 *
 * Finance V2, Phase 2D corrective pass (HIGH — coverage period integrity).
 * Four structural invariants enforced on `creating` (not a portable SQL
 * CHECK constraint — this table was already created in an earlier, now-
 * committed migration; SQLite cannot ALTER TABLE to add a CHECK after the
 * fact without a full table rebuild, so this is service/model-layer
 * enforcement instead, per the corrective-pass instruction's own fallback
 * — directly testable, applied identically on every driver):
 *   1. period_end >= period_start.
 *   2. The period lies within its ServiceCoverage's own coverage_start/
 *      coverage_end span.
 *   3. The installment belongs to the SAME invoice as the ServiceCoverage's
 *      own InvoiceItem's invoice (cross-table logical constraint, not
 *      expressible as a portable FK).
 *   4. No overlapping period for the same ServiceCoverage.
 */
class InstallmentCoveragePeriod extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'invoice_installment_id',
        'service_coverage_id',
        'period_start',
        'period_end',
        'amount',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $period): void {
            $period->validateIntegrity();
        });
        static::saving(function (self $period): void {
            if ($period->exists) {
                throw new LogicException('Период покрытия этапа нельзя изменить.');
            }
        });
        static::deleting(fn () => throw new LogicException('Период покрытия этапа нельзя удалить.'));
    }

    private function validateIntegrity(): void
    {
        $start = $this->period_start instanceof \Carbon\Carbon ? $this->period_start : \Illuminate\Support\Carbon::parse($this->period_start);
        $end = $this->period_end instanceof \Carbon\Carbon ? $this->period_end : \Illuminate\Support\Carbon::parse($this->period_end);
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['period_end' => 'Окончание периода не может быть раньше его начала.']);
        }

        // Corrective pass #2 (HIGH 6 — coverage-period integrity and
        // concurrency): the coverage row is locked before the overlap
        // check below runs, inside whatever transaction the caller
        // opened (InvoiceIssuanceService::issue() always does) — two
        // concurrent inserts for the SAME coverage now serialize on this
        // lock instead of both reading "no overlap yet" and racing each
        // other into the table.
        $coverage = ServiceCoverage::with('invoiceItem.invoice')->lockForUpdate()->findOrFail($this->service_coverage_id);
        if ($start->lt($coverage->coverage_start) || $end->gt($coverage->coverage_end)) {
            throw ValidationException::withMessages(['period_start' => 'Период выходит за границы покрытия услуги.']);
        }

        $installment = InvoiceInstallment::findOrFail($this->invoice_installment_id);
        if ($installment->invoice_id !== $coverage->invoiceItem?->invoice_id) {
            throw ValidationException::withMessages(['invoice_installment_id' => 'Этап оплаты принадлежит другому счёту, чем покрытие услуги.']);
        }

        $overlaps = static::where('service_coverage_id', $this->service_coverage_id)
            ->where('period_start', '<=', $end->toDateString())
            ->where('period_end', '>=', $start->toDateString())
            ->exists();
        if ($overlaps) {
            throw ValidationException::withMessages(['period_start' => 'Период пересекается с уже существующим периодом этого покрытия.']);
        }
    }

    public function installment()
    {
        return $this->belongsTo(InvoiceInstallment::class, 'invoice_installment_id');
    }

    public function coverage()
    {
        return $this->belongsTo(ServiceCoverage::class, 'service_coverage_id');
    }

    /** Corrective pass #2 (P0 Blocker 2) — every explicit payment-to-period allocation for this specific period. */
    public function paymentAllocationCoveragePeriods()
    {
        return $this->hasMany(PaymentAllocationCoveragePeriod::class, 'installment_coverage_period_id');
    }

    /** Corrective pass #3 (P0 Blocker 1D) — every explicit refund-to-period reversal for this specific period. */
    public function paymentRefundAllocationCoveragePeriods()
    {
        return $this->hasMany(PaymentRefundAllocationCoveragePeriod::class, 'installment_coverage_period_id');
    }

    /** Corrective pass #3 (P0 Blocker 1E) — every explicit credit-application-to-period settlement for this specific period. */
    public function creditApplicationCoveragePeriods()
    {
        return $this->hasMany(CreditApplicationCoveragePeriod::class, 'installment_coverage_period_id');
    }

    /** Corrective pass #3 (P0 Blocker 1) — gross cash allocated to this period, before any refund. */
    public function grossPaymentAllocated(): string
    {
        return bcadd((string) $this->paymentAllocationCoveragePeriods()->sum('amount'), '0', 2);
    }

    /** Corrective pass #3 (P0 Blocker 1D) — gross amount reversed from this period by refunds. */
    public function grossRefunded(): string
    {
        return bcadd((string) $this->paymentRefundAllocationCoveragePeriods()->sum('amount'), '0', 2);
    }

    /** Corrective pass #3 (P0 Blocker 1E) — gross credit explicitly applied to this period. */
    public function grossCreditApplied(): string
    {
        return bcadd((string) $this->creditApplicationCoveragePeriods()->sum('amount'), '0', 2);
    }

    /**
     * Corrective pass #3 (P0 Blocker 1) — net settled = cash allocated
     * + credit applied - refunded, all scoped to THIS specific period.
     * Never inferred from installment-level remaining_amount/status —
     * the original allocation/refund/credit rows are never mutated or
     * deleted; this is always a live sum over them.
     */
    public function netSettledAmount(): string
    {
        return bcsub(bcadd($this->grossPaymentAllocated(), $this->grossCreditApplied(), 2), $this->grossRefunded(), 2);
    }

    /** Corrective pass #3 (P0 Blocker 1) — how much of this period's own full amount remains outstanding (never negative). */
    public function remainingAmount(): ?string
    {
        if ($this->amount === null) {
            return null;
        }
        $remaining = bcsub((string) $this->amount, $this->netSettledAmount(), 2);

        return bccomp($remaining, '0.00', 2) < 0 ? '0.00' : $remaining;
    }

    /**
     * Finance V2, Phase 2D corrective pass #1 (HIGH — payment<->coverage
     * explicitness) investigated StudentCreditService::apply() directly:
     * confirmed it never touches InvoiceInstallment.
     *
     * Corrective pass #2 (P0 Blocker 2) moved this off installment-level
     * remaining_amount/status entirely, onto the explicit PaymentAllocation
     * -> PaymentAllocationCoveragePeriod chain, since a SHARED installment's
     * own remaining_amount cannot distinguish "Transport's own period
     * settled, Tuition's own period didn't."
     *
     * Corrective pass #3 (P0 Blocker 1) extends the SAME discipline to
     * refunds and credit — net settlement, never gross, and never
     * inferred from anything installment-level:
     *
     *  - 'unpaid': no InvoicePayment against this period's own
     *    installment AND no credit ever applied to this period, and net
     *    settled <= 0.
     *  - 'unallocated': at least one payment against this installment
     *    has NO PaymentAllocation at all for this period's own
     *    InvoiceItem (Phase 1C's legacy/ambiguous multi-item exception —
     *    unchanged from pass #2), OR at least one StudentCreditApplicationItem
     *    exists for this period's own InvoiceItem with NO period-level
     *    breakdown at all (credit was applied to the ITEM but which
     *    period(s) it settled is genuinely unrecorded) — either way,
     *    this period's true settlement is unknowable from the data, and
     *    is NEVER guessed or backfilled.
     *  - 'unknown': this row predates the 'amount' column (no reference
     *    amount to compare net settlement against).
     *  - 'settled' / 'partial' / 'unpaid': every payment against this
     *    installment IS explicitly allocated (and every credit
     *    application touching this item IS explicitly period-attributed)
     *    — net settled amount (payments + credit - refunds) is compared
     *    directly against this period's own recorded 'amount'.
     */
    public function settlementStatus(): string
    {
        // Zero capacity represents a fully waived receivable period. It is
        // settled without a payment allocation, never unpaid or ambiguous.
        if ($this->amount !== null && bccomp((string) $this->amount, '0.00', 2) === 0) {
            return 'settled';
        }

        $paymentIds = InvoicePayment::where('invoice_installment_id', $this->invoice_installment_id)->pluck('id');
        $hasUnallocatedPayment = $paymentIds->contains(
            fn ($paymentId) => ! PaymentAllocation::where('invoice_payment_id', $paymentId)->exists()
        );

        $itemId = $this->coverage()->value('invoice_item_id');
        $hasCreditWithoutPeriodAttribution = StudentCreditApplicationItem::where('invoice_item_id', $itemId)
            ->whereDoesntHave('coveragePeriods')
            ->exists();

        if ($hasUnallocatedPayment || $hasCreditWithoutPeriodAttribution) {
            return 'unallocated';
        }

        if ($this->amount === null) {
            return 'unknown';
        }

        $net = $this->netSettledAmount();
        if (bccomp($net, '0.00', 2) <= 0) {
            return 'unpaid';
        }

        return bccomp($net, (string) $this->amount, 2) >= 0 ? 'settled' : 'partial';
    }

    public function isSettled(): bool
    {
        return $this->settlementStatus() === 'settled';
    }
}
