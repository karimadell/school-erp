<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeeBillingPeriod;
use App\Models\PaymentPlan;
use App\Services\Finance\HistoricalBillingCompatibilityService;
use Illuminate\Support\Facades\DB;

/**
 * Finance V2, Phase 2D corrective pass (P0 Blocker 4).
 *
 * Proves the retroactive migration (2026_09_02_110000_retroactively_flag_uat_test_payment_plan)
 * correctly flags a PRE-EXISTING "UAT — 2 платежа 50/50" plan row (simulating
 * one created by an earlier UatMasterDataRepair run, before is_test_data
 * existed) and removes any fee_payment_plan grant that existed solely
 * because of it — while a genuinely different, real historical plan grant
 * on the same or another Fee is left untouched, and Registration stays
 * once-only throughout regardless.
 */
class UatTestPlanRetroactiveFixTest extends FinanceOperationsTestCase
{
    private function runRetroactiveFixMigration(): void
    {
        // Anonymous-class migration — included and invoked directly,
        // exactly like it runs during a real `php artisan migrate`, since
        // RefreshDatabase has already run every migration (including this
        // one) once by the time a test starts; this re-runs its `up()`
        // idempotently against fixture data shaped like a pre-existing row.
        (include base_path('database/migrations/2026_09_02_110000_retroactively_flag_uat_test_payment_plan.php'))->up();
    }

    public function test_a_pre_existing_uat_plan_row_is_retroactively_flagged_as_test_data(): void
    {
        // Simulates a plan created by an earlier UatMasterDataRepair run,
        // before is_test_data existed — is_test_data explicitly forced
        // false here to represent that pre-existing, un-flagged state.
        $plan = PaymentPlan::create(['name_ru' => 'UAT — 2 платежа 50/50', 'is_active' => true, 'is_test_data' => false]);

        $this->runRetroactiveFixMigration();

        $this->assertTrue($plan->fresh()->is_test_data);
    }

    public function test_fee_payment_plan_grant_solely_from_the_uat_plan_is_removed(): void
    {
        $plan = PaymentPlan::create(['name_ru' => 'UAT — 2 платежа 50/50', 'is_active' => true, 'is_test_data' => false]);
        $uniform = Fee::create(['name_ru' => 'Форма (retro)', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '500.00', 'is_active' => true]);
        $uniform->billingPeriods()->create(['billing_period' => FeeBillingPeriod::PERIOD_CUSTOM_PLAN]);
        DB::table('fee_payment_plan')->insert(['fee_id' => $uniform->id, 'payment_plan_id' => $plan->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->runRetroactiveFixMigration();

        $uniform->refresh();
        $this->assertFalse($uniform->allowsBillingPeriod(FeeBillingPeriod::PERIOD_CUSTOM_PLAN), 'grant solely from the now-flagged test plan is removed');
        $this->assertSame(0, DB::table('fee_payment_plan')->where('payment_plan_id', $plan->id)->count());
    }

    public function test_an_independent_real_plan_grant_on_the_same_fee_is_preserved(): void
    {
        $testPlan = PaymentPlan::create(['name_ru' => 'UAT — 2 платежа 50/50', 'is_active' => true, 'is_test_data' => false]);
        $realPlan = PaymentPlan::create(['name_ru' => 'Настоящий план (retro)', 'is_active' => true, 'is_test_data' => false]);
        $uniform = Fee::create(['name_ru' => 'Форма (retro, оба)', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '500.00', 'is_active' => true]);
        $uniform->billingPeriods()->create(['billing_period' => FeeBillingPeriod::PERIOD_CUSTOM_PLAN]);
        DB::table('fee_payment_plan')->insert(['fee_id' => $uniform->id, 'payment_plan_id' => $testPlan->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fee_payment_plan')->insert(['fee_id' => $uniform->id, 'payment_plan_id' => $realPlan->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->runRetroactiveFixMigration();

        $uniform->refresh();
        $this->assertTrue($uniform->allowsBillingPeriod(FeeBillingPeriod::PERIOD_CUSTOM_PLAN), 'the independent real-plan grant keeps the Fee eligible');
        $this->assertTrue($uniform->assignedPaymentPlans()->where('payment_plans.id', $realPlan->id)->exists());
        $this->assertFalse($uniform->assignedPaymentPlans()->where('payment_plans.id', $testPlan->id)->exists(), 'the test plan itself is still removed');
    }

    public function test_registration_stays_once_only_throughout_the_retroactive_fix(): void
    {
        $testPlan = PaymentPlan::create(['name_ru' => 'UAT — 2 платежа 50/50', 'is_active' => true, 'is_test_data' => false]);
        $registration = Fee::create(['name_ru' => 'Регистрация (retro)', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1000.00', 'is_active' => true]);

        $this->runRetroactiveFixMigration();
        app(HistoricalBillingCompatibilityService::class)->grantHistoricalCustomPlanAssignments();

        $registration->refresh();
        $this->assertFalse($registration->allowsBillingPeriod(FeeBillingPeriod::PERIOD_CUSTOM_PLAN));
    }

    public function test_running_the_migration_twice_is_idempotent(): void
    {
        $plan = PaymentPlan::create(['name_ru' => 'UAT — 2 платежа 50/50', 'is_active' => true, 'is_test_data' => false]);

        $this->runRetroactiveFixMigration();
        $this->runRetroactiveFixMigration();

        $this->assertTrue($plan->fresh()->is_test_data);
        $this->assertSame(1, PaymentPlan::where('name_ru', 'UAT — 2 платежа 50/50')->count());
    }
}
