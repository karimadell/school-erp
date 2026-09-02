<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance V2, Phase 2D corrective pass #3 (P0 Blocker 1E). Mirrors
 * payment_allocation_coverage_periods, one level further down from
 * student_credit_application_items — which InstallmentCoveragePeriod a
 * slice of an item-level credit application actually settles. Unlike
 * the payment side, one StudentCreditApplicationItem is NOT guaranteed
 * to map to only one installment/period (a credit application is not
 * scoped to a single installment the way InvoicePaymentService::record()
 * is) — a caller may explicitly attribute one item-level credit slice
 * across several of that item's own coverage periods, so this is a
 * genuine one-to-many mapping, validated by the service layer (sum of
 * period amounts must equal the item-level amount, when supplied).
 *
 * Write-once, immutable, append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_application_coverage_periods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_credit_application_item_id')
                ->constrained('student_credit_application_items')
                ->restrictOnDelete();

            $table->foreignId('installment_coverage_period_id')
                ->constrained('installment_coverage_periods')
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['student_credit_application_item_id', 'installment_coverage_period_id'], 'credit_app_cov_period_unique');
            $table->index('installment_coverage_period_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_application_coverage_periods');
    }
};
