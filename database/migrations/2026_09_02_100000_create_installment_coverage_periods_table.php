<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance V2, Phase 2D — explicit prepayment coverage
 * (docs/finance-v2-architecture.md).
 *
 * Ties one calendar-generated InvoiceInstallment to the SPECIFIC calendar
 * period it represents — deliberately a separate, immutable fact from that
 * installment's own due_date. due_date is "when money is owed"; a row here
 * is "which service dates that money purchases." The first installment of
 * any schedule already has these two facts diverge on purpose (no
 * proration: due_date is the actual registration date, period_start is
 * always that calendar month/quarter's real 1st).
 *
 * A dedicated table rather than columns on invoice_installments: that
 * table also serves non-calendar billing (generateSingle(), percentage-
 * based PaymentPlan.generate()) where "period" has no meaning at all —
 * bolting nullable period columns onto it would mix concerns across rows
 * the concept doesn't apply to. Matches this project's own established
 * shape for a narrow, auditable "which X represents which Y" fact
 * (payment_allocations is the direct precedent: small, immutable, FK'd,
 * created_at-only, no updated_at).
 *
 * (invoice_installment_id, service_coverage_id) is unique, not
 * invoice_installment_id alone: an invoice's calendar schedule is shared
 * across every Fee on it (M1's rule — all Fees on one invoice share one
 * billing strategy, so there is exactly one installment sequence for the
 * whole invoice, splitting the COMBINED total). When more than one
 * periodic Fee is bundled on the same invoice, each Fee gets its own
 * ServiceCoverage span, and the SAME shared installment therefore
 * represents the same calendar period for each of them — one row per
 * (installment, coverage) pair, all sharing identical period_start/
 * period_end for a given installment. A single Fee invoice (the common
 * case) simply has exactly one row per installment.
 *
 * service_coverage_id ties the period back to the Fee-level coverage span
 * (Phase 2D item 2) — required (not nullable): a period-mapping row only
 * ever exists for a Fee that actually has automatic coverage, currently
 * monthly-billed Fees only (ServiceCoverage's own billing_unit constraint
 * — monthly/daily only — means quarterly/yearly calendar schedules get no
 * ServiceCoverage and therefore no rows here either; see the Phase 2D
 * implementation report for this explicit, honest scope boundary).
 *
 * "Which periods were paid" is queryable via a plain join through the
 * installment's own existing status/remaining_amount/payments() — no
 * second, competing "paid periods" mechanism is introduced here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_coverage_periods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_installment_id')
                ->constrained('invoice_installments')
                ->restrictOnDelete();

            $table->foreignId('service_coverage_id')
                ->constrained('service_coverages')
                ->restrictOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['invoice_installment_id', 'service_coverage_id']);
            $table->index('service_coverage_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_coverage_periods');
    }
};
