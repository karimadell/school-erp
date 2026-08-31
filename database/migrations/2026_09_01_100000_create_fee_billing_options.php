<?php

use App\Models\Fee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance V2, Phase 2B — service-aware billing schedules
 * (docs/finance-v2-architecture.md).
 *
 * Two additive, purely new tables. No column on `fees`/`payment_plans` is
 * touched — the pre-existing, unused `fees.billing_period` column is left
 * exactly as-is (removing dead columns is separate cleanup, not bundled
 * here).
 *
 * `fee_billing_periods`: which billing periods (once/monthly/quarterly/
 * yearly/custom_plan) a given Fee allows. A Fee may allow more than one —
 * e.g. Tuition allowing monthly, quarterly, and yearly simultaneously — so
 * this is a one-to-many child table, not a single enum column on `fees`.
 *
 * `fee_payment_plan`: which specific, admin-configured PaymentPlan(s) are
 * explicitly assigned to a Fee. Only meaningful when that Fee also allows
 * the `custom_plan` billing period. This is the fix for the reported UAT
 * bug — PaymentPlan is no longer implicitly offered to every Fee; it must
 * be explicitly assigned here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_billing_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_id')->constrained()->cascadeOnDelete();
            $table->string('billing_period');
            $table->timestamps();

            $table->unique(['fee_id', 'billing_period']);
        });

        Schema::create('fee_payment_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_plan_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['fee_id', 'payment_plan_id']);
        });

        // Sane defaults for existing Fees, so current fixtures/flows keep
        // working once billing-period validation is enforced. Explicit,
        // clearly-labeled seeding — never inferred from the old, unused
        // `fees.billing_period` column (its values were never curated).
        $now = now();
        $rows = [];

        Fee::where('category', Fee::CATEGORY_REGISTRATION)->pluck('id')->each(function ($id) use (&$rows, $now) {
            $rows[] = ['fee_id' => $id, 'billing_period' => 'once', 'created_at' => $now, 'updated_at' => $now];
        });

        Fee::whereIn('category', [
            Fee::CATEGORY_TUITION, Fee::CATEGORY_TUITION_REGULAR, Fee::CATEGORY_TUITION_FAMILY, Fee::CATEGORY_TUITION_EXTERNAL,
        ])->pluck('id')->each(function ($id) use (&$rows, $now) {
            foreach (['monthly', 'quarterly', 'yearly'] as $period) {
                $rows[] = ['fee_id' => $id, 'billing_period' => $period, 'created_at' => $now, 'updated_at' => $now];
            }
        });

        // Transport and Food: their own starter set (monthly + yearly) —
        // deliberately not identical to Tuition's set (no quarterly), since
        // this is a distinct, explicitly-configured choice per the approved
        // design, not an inherited default.
        Fee::whereIn('category', [Fee::CATEGORY_TRANSPORT, Fee::CATEGORY_FOOD])->pluck('id')->each(function ($id) use (&$rows, $now) {
            foreach (['monthly', 'yearly'] as $period) {
                $rows[] = ['fee_id' => $id, 'billing_period' => $period, 'created_at' => $now, 'updated_at' => $now];
            }
        });

        if ($rows !== []) {
            DB::table('fee_billing_periods')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payment_plan');
        Schema::dropIfExists('fee_billing_periods');
    }
};
