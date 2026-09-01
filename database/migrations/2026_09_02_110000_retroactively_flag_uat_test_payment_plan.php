<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finance V2, Phase 2D corrective pass (P0 Blocker 4).
 *
 * Stage A's is_test_data column defaults false, correctly flagging a
 * NEWLY-created UAT 50/50 plan (UatMasterDataRepair's create path already
 * sets is_test_data=true there). But if that exact plan already existed in
 * a database from an EARLIER run of UatMasterDataRepair (before this
 * column existed — its own dry-run logic has a "SKIP (already exists)"
 * branch that never touches an existing row), that existing row would be
 * left incorrectly un-flagged by Stage A's migration alone.
 *
 * This is a one-time, exact-match retroactive repair for ONE specific,
 * already-established fixture identity — not a general name-pattern
 * exclusion mechanism (the earlier design review's objection was to broad
 * pattern matching as the general HistoricalBillingCompatibilityService
 * exclusion, correctly avoided there via the explicit boolean column; this
 * is a narrower, one-time data correction for that one known row).
 *
 * After flagging, also removes any fee_payment_plan grant that exists
 * SOLELY because of this specific plan's historical usage — Fee-eligibility
 * CONFIGURATION data, not a financial transaction, safe to correct —
 * unless the same Fee also has an independently-justified grant from a
 * genuinely different, real (non-test) plan, which is left untouched.
 * Given the corrected migration ordering (this Blocker-4 migration runs
 * chronologically after Stage A's is_test_data column exists, and Stage
 * A's own historical-compatibility grant step already excludes
 * is_test_data=true plans), a fresh/first-time migration run should never
 * actually produce such a grant in the first place — this is a defensive
 * correctness measure for a database where the grant migration already
 * ran before this fix existed.
 */
return new class extends Migration
{
    private const UAT_TEST_PLAN_NAME = 'UAT — 2 платежа 50/50';

    public function up(): void
    {
        $planIds = DB::table('payment_plans')
            ->where('name_ru', self::UAT_TEST_PLAN_NAME)
            ->pluck('id');

        if ($planIds->isEmpty()) {
            return;
        }

        DB::table('payment_plans')->whereIn('id', $planIds)->update(['is_test_data' => true]);

        foreach ($planIds as $planId) {
            $affectedFeeIds = DB::table('fee_payment_plan')->where('payment_plan_id', $planId)->pluck('fee_id');
            foreach ($affectedFeeIds as $feeId) {
                $hasIndependentRealGrant = DB::table('fee_payment_plan')
                    ->join('payment_plans', 'payment_plans.id', '=', 'fee_payment_plan.payment_plan_id')
                    ->where('fee_payment_plan.fee_id', $feeId)
                    ->where('fee_payment_plan.payment_plan_id', '!=', $planId)
                    ->where('payment_plans.is_test_data', false)
                    ->exists();

                if (! $hasIndependentRealGrant) {
                    DB::table('fee_billing_periods')
                        ->where('fee_id', $feeId)
                        ->where('billing_period', 'custom_plan')
                        ->delete();
                }
            }
            DB::table('fee_payment_plan')->where('payment_plan_id', $planId)->delete();
        }
    }

    public function down(): void
    {
        // Deliberately no-op: reversing would mean re-flagging a known
        // test plan as non-test and potentially re-granting eligibility
        // that was correctly removed — never automatic, per this
        // project's no-guessing policy on financial/eligibility data.
    }
};
