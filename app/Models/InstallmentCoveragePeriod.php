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

    /**
     * Finance V2, Phase 2D corrective pass #1 (HIGH — payment<->coverage
     * explicitness) investigated StudentCreditService::apply() directly:
     * it never touches InvoiceInstallment, so a bundled installment's own
     * remaining_amount reflects only genuine InvoicePayment activity.
     *
     * Corrective pass #2 (P0 Blocker 2): that investigation also
     * surfaced the real remaining gap — a SHARED installment's own
     * remaining_amount is an INSTALLMENT-level fact, not a per-service
     * one, so it cannot by itself distinguish "Transport's own period
     * settled, Tuition's own period didn't" when the two Fees share one
     * installment and only one of them was explicitly paid. This method
     * now answers that at the correct granularity, using the explicit
     * PaymentAllocation -> PaymentAllocationCoveragePeriod chain instead:
     *
     *  - 'unpaid': no InvoicePayment at all against this period's own
     *    installment.
     *  - 'unallocated': at least one payment against this installment has
     *    NO PaymentAllocation at all for this period's own InvoiceItem
     *    (Phase 1C's legacy/ambiguous multi-item exception) — this
     *    period's true settlement is genuinely unknowable from the data,
     *    and is NEVER guessed or backfilled; every validated allocation
     *    always sums to exactly its payment's own amount (validateAllocations()),
     *    so a payment is either fully allocated or not allocated at all —
     *    never partially, which keeps this a clean binary per payment.
     *  - 'unknown': this row predates the 'amount' column (a legacy
     *    installment_coverage_periods row with no recorded full amount to
     *    compare against) — distinct from every other state, never
     *    reported as settled or partial without a real reference amount.
     *  - 'settled' / 'partial' / 'unpaid': every payment against this
     *    installment IS explicitly allocated, and the sum of this
     *    period's own PaymentAllocationCoveragePeriod rows is compared
     *    directly against this period's own recorded 'amount' — the
     *    correct per-service, per-period reference, never the shared
     *    installment total.
     */
    public function settlementStatus(): string
    {
        $payments = InvoicePayment::where('invoice_installment_id', $this->invoice_installment_id)->get(['id']);
        if ($payments->isEmpty()) {
            return 'unpaid';
        }

        $paymentIds = $payments->pluck('id');
        $hasUnallocatedPayment = $paymentIds->contains(
            fn ($paymentId) => ! PaymentAllocation::where('invoice_payment_id', $paymentId)->exists()
        );
        if ($hasUnallocatedPayment) {
            return 'unallocated';
        }

        if ($this->amount === null) {
            return 'unknown';
        }

        $settled = bcadd((string) $this->paymentAllocationCoveragePeriods()->sum('amount'), '0', 2);
        if (bccomp($settled, '0.00', 2) <= 0) {
            return 'unpaid';
        }

        return bccomp($settled, (string) $this->amount, 2) >= 0 ? 'settled' : 'partial';
    }

    public function isSettled(): bool
    {
        return $this->settlementStatus() === 'settled';
    }
}
