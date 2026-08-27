<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\MealPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * UAT ran the (Postgres) Phase 4A audit against real legacy Food data and
 * crashed: SQLSTATE[22P02] invalid input syntax for type bigint. Legacy
 * FeePrice rows carry a pre-migration textual meal name (e.g. "Напиток") in
 * option_value, and the audit's classify() unconditionally queried
 * meal_plans.id with that raw value — safe on SQLite/MySQL (silently no
 * match), fatal on a strictly-typed Postgres bigint column.
 *
 * The command itself is read-only regardless of database engine; these
 * tests prove the fixed classification logic never reaches that query with
 * a non-numeric value, so it cannot crash on any engine, not just ones
 * lenient enough to hide the bug locally.
 */
class FinanceReadinessAuditLegacyFoodDataTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;
    private Fee $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true]);
        $this->food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '300.00', 'is_active' => true]);
    }

    private function price(array $overrides): FeePrice
    {
        return FeePrice::create(array_merge([
            'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id, 'amount' => '300.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'meal_plan',
        ], $overrides));
    }

    public function test_a_textual_legacy_meal_name_does_not_crash_the_audit(): void
    {
        $this->price(['option_value' => 'Напиток']);

        $exitCode = Artisan::call('finance:readiness-audit', ['--year' => '2026/2027']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('LEGACY_MEAL_PLAN_VALUE', $output);
        $this->assertStringContainsString('Напиток', $output);
        $this->assertStringContainsString('INVALID DIMENSION', $output);
    }

    public function test_a_nonexistent_numeric_meal_plan_id_is_reported_not_crashed(): void
    {
        $this->price(['option_value' => '9999']);

        $exitCode = Artisan::call('finance:readiness-audit', ['--year' => '2026/2027']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('does not resolve to an existing MealPlan', $output);
        $this->assertStringContainsString('9999', $output);
        $this->assertStringNotContainsString('LEGACY_MEAL_PLAN_VALUE', $output);
    }

    public function test_a_valid_numeric_meal_plan_id_is_reported_sellable(): void
    {
        $mealPlan = MealPlan::create(['name_ru' => 'Обед', 'meal_type' => MealPlan::TYPE_LUNCH, 'period' => MealPlan::PERIOD_MONTHLY, 'price' => '300.00', 'is_active' => true]);
        $this->price(['option_value' => (string) $mealPlan->id]);

        $exitCode = Artisan::call('finance:readiness-audit', ['--year' => '2026/2027']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SELLABLE', $output);
    }

    public function test_the_audit_completes_all_sections_with_mixed_valid_and_invalid_food_rows(): void
    {
        $mealPlan = MealPlan::create(['name_ru' => 'Обед', 'meal_type' => MealPlan::TYPE_LUNCH, 'period' => MealPlan::PERIOD_MONTHLY, 'price' => '300.00', 'is_active' => true]);
        $this->price(['option_value' => (string) $mealPlan->id]);
        $this->price(['option_value' => 'Завтрак']);
        $this->price(['option_value' => 'Обед']);
        $this->price(['option_value' => '9999']);

        $exitCode = Artisan::call('finance:readiness-audit', ['--year' => '2026/2027']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Audit complete. No data was created, updated, or deleted.', $output);
        $this->assertStringContainsString('M. Final readiness matrix', $output);
        $this->assertStringContainsString('LEGACY_MEAL_PLAN_VALUE', $output);
        $this->assertStringContainsString('does not resolve to an existing MealPlan', $output);
        $this->assertStringContainsString('SELLABLE', $output);
    }
}
