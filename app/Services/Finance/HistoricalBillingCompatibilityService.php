<?php

namespace App\Services\Finance;

use App\Models\Fee;
use App\Models\FeeBillingPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Finance V2, Phase 2B corrective pass (review finding H1).
 *
 * Grants 'custom_plan' billing-period eligibility + the specific
 * PaymentPlan assignment to any Fee that was ACTUALLY invoiced with that
 * plan historically — before this phase's Fee-scoping validation existed,
 * ANY active PaymentPlan was offered to ANY Fee. Without this, a Fee
 * outside the migration's default category seeding (Registration/Tuition/
 * Transport/Food) that genuinely relied on a plan-based schedule in the
 * past would silently lose that capability the moment InvoiceIssuanceService
 * starts enforcing Fee-scoped plan assignment.
 *
 * Read-only against invoices/invoice_items/invoice_installments — never
 * writes to historical financial data. Write-only to fee_billing_periods/
 * fee_payment_plan, the two purely-additive Phase 2B configuration tables.
 * Idempotent: safe to call multiple times, never produces duplicate rows
 * (relies on the unique constraints on both tables via firstOrCreate).
 *
 * Deliberately NOT a blanket grant: a Fee that was never actually invoiced
 * with a plan gets nothing from this service.
 */
class HistoricalBillingCompatibilityService
{
    /**
     * @return array<int, array{fee_id: int, payment_plan_id: int}> every
     *         (fee_id, payment_plan_id) pair this run ensured is granted
     *         (whether newly created or already present)
     */
    public function grantHistoricalCustomPlanAssignments(): array
    {
        // Every (fee_id, payment_plan_id) pair that was ever actually used
        // together: a plan-based InvoiceInstallment implies every Fee on
        // that same invoice was billed under that plan (a plan-based
        // schedule applies to the whole invoice, never a single line item)
        // — matching exactly how InvoiceIssuanceService::issue() itself
        // validates "every Fee on the invoice must have the plan assigned".
        // Pre-deploy safety patch (Phase 2B):
        // - Registration is a hard, non-negotiable "once only" business
        //   invariant (enforced elsewhere for new invoices) — historical
        //   data, including any pre-Phase-2B mistake or ad-hoc test usage,
        //   must never be allowed to grant it custom_plan eligibility. Hard
        //   excluded from the detection query itself, not just filtered
        //   after the fact.
        // - Plans flagged is_test_data (e.g. UatMasterDataRepair's UAT
        //   50/50 plan) are pure fixture data, not real business history —
        //   excluded from the join so their historical usage never counts
        //   as evidence a Fee legitimately needs a custom-plan grant.
        $pairs = DB::table('invoice_installments')
            ->join('invoice_items', 'invoice_items.invoice_id', '=', 'invoice_installments.invoice_id')
            ->join('fees', 'fees.id', '=', 'invoice_items.fee_id')
            ->join('payment_plans', 'payment_plans.id', '=', 'invoice_installments.payment_plan_id')
            ->whereNotNull('invoice_installments.payment_plan_id')
            ->where('fees.category', '!=', Fee::CATEGORY_REGISTRATION)
            ->where('payment_plans.is_test_data', false)
            ->select('invoice_items.fee_id', 'invoice_installments.payment_plan_id')
            ->distinct()
            ->get();

        $granted = [];

        foreach ($pairs as $pair) {
            FeeBillingPeriod::firstOrCreate([
                'fee_id' => $pair->fee_id,
                'billing_period' => FeeBillingPeriod::PERIOD_CUSTOM_PLAN,
            ]);

            DB::table('fee_payment_plan')->insertOrIgnore([
                'fee_id' => $pair->fee_id,
                'payment_plan_id' => $pair->payment_plan_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $granted[] = ['fee_id' => $pair->fee_id, 'payment_plan_id' => $pair->payment_plan_id];
        }

        return $granted;
    }
}
