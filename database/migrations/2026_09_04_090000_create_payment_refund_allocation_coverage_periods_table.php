<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance V2, Phase 2D corrective pass #3 (P0 Blocker 1D — refunds must
 * reduce period settlement).
 *
 * Mirrors payment_refund_allocations (Phase 1D) exactly, one level
 * further, the same way payment_allocation_coverage_periods (corrective
 * pass #2) mirrors payment_allocations — which InstallmentCoveragePeriod
 * a slice of a confirmed refund actually reverses. Since a
 * PaymentRefundAllocation always reverses ONE PaymentAllocation, and a
 * PaymentAllocation maps to AT MOST one InstallmentCoveragePeriod
 * (installment_coverage_periods' own uniqueness), this is again a
 * genuine 1:1 mapping, not an arbitrary split.
 *
 * Write-once, immutable, append-only — same convention as every other
 * Finance V2 allocation-chain table. NEVER deletes or rewrites the
 * original PaymentAllocationCoveragePeriod row; net settlement is
 * computed by summing both tables, never by mutating either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refund_allocation_coverage_periods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_refund_allocation_id')
                ->constrained('payment_refund_allocations')
                ->restrictOnDelete();

            $table->foreignId('installment_coverage_period_id')
                ->constrained('installment_coverage_periods')
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['payment_refund_allocation_id', 'installment_coverage_period_id'], 'pr_alloc_cov_period_unique');
            $table->index('installment_coverage_period_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refund_allocation_coverage_periods');
    }
};
