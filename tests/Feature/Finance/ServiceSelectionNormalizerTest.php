<?php

namespace Tests\Feature\Finance;

use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\MealPlan;
use App\Models\Stage;
use App\Services\Finance\ServiceSelectionNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unified Collection foundation (PR A) — isolation tests for the extracted
 * ServiceSelectionNormalizer, calling normalize() directly (no Student/
 * Enrollment/Invoice involved) to prove it produces the exact canonical
 * shape QuickStudentRegistrationService's own inline flatMap() used to
 * produce, for the category-specific branches that closure had (Tuition
 * grade_id, Transport zone/option_value, Food meal_plan option_value,
 * Uniform one-selection-fans-out-into-N-lines, mixed billing_strategy
 * validation). The full Quick Registration Finance suite already proves
 * end-to-end behavior is unchanged; these tests pin the extracted class's
 * own contract in isolation.
 */
class ServiceSelectionNormalizerTest extends TestCase
{
    use RefreshDatabase;

    private ServiceSelectionNormalizer $normalizer;
    private Grade $grade;
    private EnrollmentMode $mode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ServiceSelectionNormalizer();
        $stage = Stage::create(['name' => 'Начальная школа', 'is_active' => true]);
        $this->grade = Grade::create(['name' => '1 класс', 'stage_id' => $stage->id]);
        $this->mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очное обучение', 'is_active' => true]);
    }

    private function fee(string $category): Fee
    {
        return Fee::create(['name_ru' => 'Услуга', 'category' => $category, 'amount' => '100.00', 'is_active' => true]);
    }

    public function test_tuition_resolves_grade_id_from_context(): void
    {
        $fee = $this->fee(Fee::CATEGORY_TUITION);

        $result = $this->normalizer->normalize(
            [['fee_id' => $fee->id, 'quantity' => 1]],
            $this->grade,
            $this->mode,
            'one_time',
            [$fee->id => $fee],
        );

        $this->assertCount(1, $result);
        $this->assertSame($this->grade->id, $result[0]['grade_id']);
        $this->assertSame($this->mode->id, $result[0]['enrollment_mode_id']);
        $this->assertNull($result[0]['option_type']);
    }

    public function test_tuition_grade_group_suppresses_grade_id(): void
    {
        $fee = $this->fee(Fee::CATEGORY_TUITION);

        $result = $this->normalizer->normalize(
            [['fee_id' => $fee->id, 'quantity' => 1, 'grade_group' => '1-4']],
            $this->grade,
            $this->mode,
            'one_time',
            [$fee->id => $fee],
        );

        $this->assertNull($result[0]['grade_id']);
    }

    public function test_transport_resolves_zone_option_and_route_name(): void
    {
        $routeId = DB::table('transport_routes')->insertGetId([
            'name' => 'Маршрут 1', 'capacity' => 40, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fee = $this->fee(Fee::CATEGORY_TRANSPORT);

        $result = $this->normalizer->normalize(
            [['fee_id' => $fee->id, 'quantity' => 1, 'transport_area' => 'north', 'transport_route_id' => $routeId]],
            $this->grade,
            $this->mode,
            'one_time',
            [$fee->id => $fee],
        );

        $this->assertSame('zone', $result[0]['option_type']);
        $this->assertSame('north', $result[0]['option_value']);
        $this->assertSame('Маршрут 1', $result[0]['transport_route_name']);
    }

    public function test_transport_with_missing_route_fails_closed_in_russian(): void
    {
        $fee = $this->fee(Fee::CATEGORY_TRANSPORT);

        $this->expectException(ValidationException::class);

        $this->normalizer->normalize(
            [['fee_id' => $fee->id, 'quantity' => 1, 'transport_area' => 'north', 'transport_route_id' => 999999]],
            $this->grade,
            $this->mode,
            'one_time',
            [$fee->id => $fee],
        );
    }

    public function test_food_resolves_meal_plan_option_and_forces_daily_period(): void
    {
        $mealPlan = MealPlan::create(['name_ru' => 'Завтрак', 'meal_type' => MealPlan::TYPE_BREAKFAST, 'period' => MealPlan::PERIOD_DAILY, 'price' => '50.00', 'is_active' => true]);
        $fee = $this->fee(Fee::CATEGORY_FOOD);

        $result = $this->normalizer->normalize(
            [['fee_id' => $fee->id, 'quantity' => 1, 'meal_plan_id' => $mealPlan->id, 'payment_period' => 'monthly']],
            $this->grade,
            $this->mode,
            'one_time',
            [$fee->id => $fee],
        );

        $this->assertSame('meal_plan', $result[0]['option_type']);
        $this->assertSame((string) $mealPlan->id, $result[0]['option_value']);
        $this->assertSame(Fee::PERIOD_DAILY, $result[0]['payment_period']);
        $this->assertSame('Завтрак', $result[0]['meal_plan_name']);
    }

    public function test_uniform_selection_fans_out_into_one_line_per_item_and_only_the_first_carries_paid_now(): void
    {
        $productA = DB::table('uniform_products')->insertGetId([
            'name_ru' => 'Рубашка', 'category' => 'shirt', 'size' => 'M', 'price' => '30.00',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $productB = DB::table('uniform_products')->insertGetId([
            'name_ru' => 'Брюки', 'category' => 'pants', 'size' => 'M', 'price' => '40.00',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fee = $this->fee(Fee::CATEGORY_UNIFORM);

        $result = $this->normalizer->normalize(
            [[
                'fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '70.00',
                'uniform_items' => [
                    ['uniform_product_id' => $productA, 'quantity' => 1],
                    ['uniform_product_id' => $productB, 'quantity' => 1],
                ],
            ]],
            $this->grade,
            $this->mode,
            'one_time',
            [$fee->id => $fee],
        );

        $this->assertCount(2, $result);
        $this->assertSame('Рубашка', $result[0]['item']);
        $this->assertSame('70.00', $result[0]['paid_now']);
        $this->assertSame('Брюки', $result[1]['item']);
        $this->assertSame('0.00', $result[1]['paid_now']);
    }

    public function test_uniform_with_no_items_fails_closed_in_russian(): void
    {
        $fee = $this->fee(Fee::CATEGORY_UNIFORM);

        $this->expectException(ValidationException::class);

        $this->normalizer->normalize(
            [['fee_id' => $fee->id, 'quantity' => 1, 'uniform_items' => []]],
            $this->grade,
            $this->mode,
            'one_time',
            [$fee->id => $fee],
        );
    }

    public function test_mixed_payment_type_defaults_to_once_billing_strategy(): void
    {
        $fee = $this->fee(Fee::CATEGORY_BOOKS);

        $result = $this->normalizer->normalize(
            [['fee_id' => $fee->id, 'quantity' => 1]],
            $this->grade,
            $this->mode,
            'mixed',
            [$fee->id => $fee],
        );

        $this->assertSame('once', $result[0]['_billing_strategy']);
        $this->assertNull($result[0]['_billing_period']);
    }

    public function test_mixed_payment_type_accepts_calendar_strategy_when_fee_supports_it(): void
    {
        $fee = $this->fee(Fee::CATEGORY_BOOKS);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);

        $result = $this->normalizer->normalize(
            [['fee_id' => $fee->id, 'quantity' => 1, 'billing_strategy' => 'calendar', 'payment_period' => 'monthly']],
            $this->grade,
            $this->mode,
            'mixed',
            [$fee->id => $fee],
        );

        $this->assertSame('calendar', $result[0]['_billing_strategy']);
        $this->assertSame('monthly', $result[0]['_billing_period']);
    }

    public function test_mixed_payment_type_rejects_calendar_strategy_for_a_fee_that_cannot_support_it(): void
    {
        $fee = $this->fee(Fee::CATEGORY_BOOKS);

        $this->expectException(ValidationException::class);

        $this->normalizer->normalize(
            [['fee_id' => $fee->id, 'quantity' => 1, 'billing_strategy' => 'calendar', 'payment_period' => 'monthly']],
            $this->grade,
            $this->mode,
            'mixed',
            [$fee->id => $fee],
        );
    }

    public function test_an_unknown_fee_id_throws_a_model_not_found_exception(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->normalizer->normalize(
            [['fee_id' => 999999, 'quantity' => 1]],
            $this->grade,
            $this->mode,
            'one_time',
            [],
        );
    }
}
