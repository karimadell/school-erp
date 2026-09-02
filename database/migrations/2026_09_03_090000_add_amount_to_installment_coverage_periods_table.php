<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance V2, Phase 2D corrective pass #2 (P0 Blocker 2 — payment-to-
 * coverage-period allocation).
 *
 * installment_coverage_periods (2026_09_02_100000) already ties one
 * InvoiceInstallment to the calendar period one Fee's ServiceCoverage
 * represents, but never recorded how much of that installment's SHARED
 * total belongs to THAT specific Fee/period — only the installment's own
 * (multi-Fee, summed) amount was known. Quarterly's partial trailing
 * group (corrective pass #2, P0 Blocker 1) makes this genuinely vary per
 * period even for a single Fee, so it can no longer be assumed to equal
 * a uniform unit_price either.
 *
 * Nullable and purely additive: every existing row (created before this
 * column existed) simply has no recorded amount — no attempt to
 * backfill/guess it after the fact. New rows always populate it (see
 * InvoiceIssuanceService::createAutomaticCoverage()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_coverage_periods', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable()->after('period_end');
        });
    }

    public function down(): void
    {
        Schema::table('installment_coverage_periods', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }
};
