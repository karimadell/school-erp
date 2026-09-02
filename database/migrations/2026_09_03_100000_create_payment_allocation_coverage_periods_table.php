<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance V2, Phase 2D corrective pass #2 (P0 Blocker 2 — explicit
 * payment-to-coverage-period allocation).
 *
 * PaymentAllocation (Phase 1A) already ties a confirmed InvoicePayment to
 * the specific InvoiceItem it pays down — but when that item itself spans
 * MULTIPLE coverage periods (e.g. Transport's own item covers 9 months),
 * an item-level allocation amount doesn't yet say WHICH of those periods
 * it settles. This is a third, small, additive layer — it does not
 * replace PaymentAllocation, it refines it one level further, exactly the
 * same way installment_coverage_periods refines InvoiceInstallment
 * without replacing it.
 *
 * Since InvoicePaymentService::record() always settles ONE specific
 * installment per call, and installment_coverage_periods is unique on
 * (invoice_installment_id, service_coverage_id), a single
 * PaymentAllocation (payment, item) pair maps to AT MOST one
 * InstallmentCoveragePeriod for that same installment — this is a
 * genuine mapping, not an arbitrary split of one allocation across many
 * periods (that scenario cannot occur under this codebase's own
 * one-payment-settles-one-installment design).
 *
 * Write-once and immutable — same convention as payment_allocations and
 * installment_coverage_periods (created_at only, no updated_at, no
 * update/delete path).
 *
 * Historical/legacy payments with no PaymentAllocation at all (Phase 1A/
 * 1C's "unallocated" bucket) naturally have no rows here either — reads
 * as explicitly unallocated/ambiguous for coverage-settlement purposes,
 * never guessed or backfilled (see InstallmentCoveragePeriod::settlement()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocation_coverage_periods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_allocation_id')
                ->constrained('payment_allocations')
                ->restrictOnDelete();

            $table->foreignId('installment_coverage_period_id')
                ->constrained('installment_coverage_periods')
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['payment_allocation_id', 'installment_coverage_period_id']);
            $table->index('installment_coverage_period_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocation_coverage_periods');
    }
};
