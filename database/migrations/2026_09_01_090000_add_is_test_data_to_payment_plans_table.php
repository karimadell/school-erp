<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B pre-deploy safety patch.
 *
 * Purely additive: existing PaymentPlan rows all default to
 * is_test_data=false (no existing plan's meaning changes). Lets
 * HistoricalBillingCompatibilityService distinguish a genuine historical
 * business plan from pure UAT/test fixture data (e.g. UatMasterDataRepair's
 * "UAT — 2 платежа 50/50") when deciding what to grant. A separate
 * migration rather than editing 2026_09_01_100000_create_fee_billing_options
 * — that migration has not yet run anywhere, but editing an already-merged
 * migration risks a silent skip for anyone who already ran it locally
 * against this commit; a new migration is always safe regardless.
 *
 * Timestamped to run BEFORE 2026_09_01_100000_create_fee_billing_options —
 * that migration's own up() invokes
 * HistoricalBillingCompatibilityService::grantHistoricalCustomPlanAssignments(),
 * which queries payment_plans.is_test_data; this column must already exist
 * by then or that call fails outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_plans', function (Blueprint $table) {
            $table->boolean('is_test_data')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('payment_plans', function (Blueprint $table) {
            $table->dropColumn('is_test_data');
        });
    }
};
