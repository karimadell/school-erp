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
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
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

        $coverage = ServiceCoverage::with('invoiceItem.invoice')->findOrFail($this->service_coverage_id);
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

    /**
     * Finance V2, Phase 2D corrective pass (HIGH — payment<->coverage
     * explicitness). Investigated directly rather than assumed:
     * StudentCreditService::apply() (read in full) only ever mutates
     * Invoice.remaining_amount/StudentCredit/StudentCreditApplication —
     * it never touches InvoiceInstallment at all (confirmed by an explicit
     * test: applying a credit leaves installment_coverage_periods rows,
     * and by extension the mapped installment's own state, completely
     * unaffected). Combined with Phase 2B's own multi-installment payment
     * rule (an installment is only ever fully settled or fully rejected,
     * never partially) — a mapped installment's own remaining_amount is
     * already an unambiguous, driftless "is this period paid" signal. A
     * small DERIVED accessor here, not a second competing stored-state
     * table, per the explicit "no redundant competing source of truth"
     * instruction.
     */
    public function isSettled(): bool
    {
        return bccomp((string) $this->installment->remaining_amount, '0.00', 2) === 0;
    }
}
