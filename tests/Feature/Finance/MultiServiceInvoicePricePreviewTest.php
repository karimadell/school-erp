<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\MealPlan;
use Illuminate\Testing\TestResponse;

class MultiServiceInvoicePricePreviewTest extends QuickRegistrationUxTestCase
{
    private AcademicYear $year;

    private Grade $grade;

    private EnrollmentMode $mode;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->year, , $this->grade, , $this->mode] = $this->structure();
        $this->year->update(['start_date' => '2026-09-01', 'end_date' => '2027-05-31']);
        $this->grade->forceFill(['name' => '4 КЛАСС', 'level' => 4])->save();
        // Food flexible-duration corrective pass: the live-pricing preview
        // endpoint resolves Food's range via FoodBillableDayCalculator,
        // which requires an AcademicCalendar for the year.
        \App\Models\AcademicCalendar::create(['academic_year_id' => $this->year->id, 'weekly_days_off' => ['fri', 'sat']]);
        $this->actingAs($this->accountant);
    }

    public function test_canonical_service_previews_resolve_independent_server_prices_that_can_be_summed(): void
    {
        $tuition = $this->service('Обучение', Fee::CATEGORY_TUITION);
        $registration = $this->service('Организационный взнос', Fee::CATEGORY_REGISTRATION);
        $transport = $this->service('Трансфер', Fee::CATEGORY_TRANSPORT);
        $food = $this->service('Питание', Fee::CATEGORY_FOOD);
        $uniform = $this->service('Форма', Fee::CATEGORY_UNIFORM);
        $externat = $this->service('Экстернат', Fee::CATEGORY_TUITION_EXTERNAL);

        $cases = [
            [$tuition, $this->tariff($tuition, '40500.00', ['grade_group' => '1–4 классы', 'payment_period' => 'yearly']), '40500.00'],
            [$registration, $this->tariff($registration, '7000.00'), '7000.00'],
            [$transport, $this->tariff($transport, '13500.00', ['option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'yearly']), '13500.00'],
            [$transport, $this->tariff($transport, '1500.00', ['option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly']), '1500.00'],
            [$transport, $this->tariff($transport, '16200.00', ['option_type' => 'zone', 'option_value' => 'Зона 2', 'payment_period' => 'yearly']), '16200.00'],
            [$transport, $this->tariff($transport, '1800.00', ['option_type' => 'zone', 'option_value' => 'Зона 2', 'payment_period' => 'monthly']), '1800.00'],
            [$food, $this->tariff($food, '170.00', ['option_type' => 'meal_plan', 'option_value' => 'Комплексное питание', 'payment_period' => 'daily']), '170.00'],
            [$food, $this->tariff($food, '70.00', ['option_type' => 'meal_plan', 'option_value' => 'Завтрак', 'payment_period' => 'daily']), '70.00'],
            [$uniform, $this->tariff($uniform, '2500.00', ['item' => 'Комплект', 'size' => '12–16']), '2500.00'],
            [$externat, $this->tariff($externat, '25600.00', ['grade_group' => '1–4 классы', 'payment_period' => 'yearly']), '25600.00'],
            [$externat, $this->tariff($externat, '3200.00', ['grade_group' => '1–4 классы', 'payment_period' => 'monthly']), '3200.00'],
        ];

        $sum = '0.00';
        foreach ($cases as [$fee, $price, $amount]) {
            $this->canonicalPreview($fee, $price)->assertOk()->assertJsonPath('amount', $amount);
            $sum = bcadd($sum, $amount, 2);
        }
        $this->assertSame('112040.00', $sum);
    }

    public function test_selected_tariff_must_match_service(): void
    {
        $transport = $this->service('Трансфер', Fee::CATEGORY_TRANSPORT);
        $food = $this->service('Питание', Fee::CATEGORY_FOOD);
        $foodPrice = $this->tariff($food, '170.00', ['option_type' => 'meal_plan', 'option_value' => 'Комплексное питание']);

        $this->canonicalPreview($transport, $foodPrice)->assertUnprocessable()->assertJsonValidationErrors('fees');
    }

    public function test_selected_tariff_rejects_inactive_wrong_year_currency_and_invalid_dates(): void
    {
        $fee = $this->service('Трансфер', Fee::CATEGORY_TRANSPORT);
        $dimensions = ['option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'yearly'];
        $inactive = $this->tariff($fee, '13500.00', $dimensions + ['is_active' => false]);
        $otherYear = AcademicYear::create(['name' => '2027/2028', 'start_date' => '2027-09-01', 'end_date' => '2028-05-31', 'is_active' => true]);
        $wrongYear = $this->tariff($fee, '13500.00', $dimensions + ['academic_year_id' => $otherYear->id]);
        $wrongCurrency = $this->tariff($fee, '13500.00', $dimensions + ['currency' => 'USD']);
        $expired = $this->tariff($fee, '13500.00', $dimensions + ['start_date' => '2026-07-01', 'end_date' => '2026-08-31']);
        $future = $this->tariff($fee, '13500.00', $dimensions + ['start_date' => '2026-10-01']);

        foreach ([$inactive, $wrongYear, $wrongCurrency, $expired, $future] as $price) {
            $this->canonicalPreview($fee, $price)->assertUnprocessable()->assertJsonValidationErrors('fees');
        }
        $this->canonicalPreview($fee, $future, ['pricing_date' => '2026-10-01'])->assertOk()->assertJsonPath('amount', '13500.00');
    }

    public function test_legacy_transport_and_food_preview_inputs_remain_supported(): void
    {
        $transport = $this->service('Трансфер', Fee::CATEGORY_TRANSPORT);
        $this->tariff($transport, '1500.00', ['option_type' => 'zone', 'option_value' => 'Каусер']);
        $meal = MealPlan::create([
            'name_ru' => 'Завтрак', 'meal_type' => MealPlan::TYPE_BREAKFAST,
            'period' => MealPlan::PERIOD_DAILY, 'price' => '70.00', 'is_active' => true,
        ]);
        $food = $this->service('Питание', Fee::CATEGORY_FOOD);
        $this->tariff($food, '70.00', ['option_type' => 'meal_plan', 'option_value' => (string) $meal->id, 'payment_period' => 'daily']);

        $this->preview($transport, ['transport_area' => 'Каусер'])->assertOk()->assertJsonPath('amount', '1500.00');
        $this->preview($food, ['meal_plan_id' => $meal->id])->assertOk()->assertJsonPath('amount', '70.00');
    }

    public function test_transport_tariff_rejects_wrong_zone_and_payment_period(): void
    {
        $transport = $this->service('Трансфер', Fee::CATEGORY_TRANSPORT);
        $price = $this->tariff($transport, '1500.00', [
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly',
        ]);

        $this->canonicalPreview($transport, $price, ['option_value' => 'Зона 2'])
            ->assertUnprocessable()->assertJsonValidationErrors('fees');
        $this->canonicalPreview($transport, $price, ['payment_period' => 'yearly'])
            ->assertUnprocessable()->assertJsonValidationErrors('fees');
    }

    public function test_tariff_controlled_service_never_falls_back_to_placeholder_base_amount(): void
    {
        $transport = $this->service('Трансфер', Fee::CATEGORY_TRANSPORT, '1.00');
        $this->tariff($transport, '1500.00', [
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly',
        ]);

        $this->preview($transport, ['option_type' => 'zone', 'option_value' => 'Зона 2', 'payment_period' => 'monthly'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fees');
        $this->preview($transport)->assertUnprocessable()->assertJsonValidationErrors('fees');
    }

    public function test_ordinary_service_without_tariffs_can_use_its_base_amount(): void
    {
        $activity = $this->service('Кружок', Fee::CATEGORY_OTHER, '350.00');

        $this->preview($activity)->assertOk()->assertJsonPath('amount', '350.00');
    }

    private function canonicalPreview(Fee $fee, FeePrice $price, array $overrides = []): TestResponse
    {
        return $this->preview($fee, array_replace([
            'fee_price_id' => $price->id, 'grade_group' => $price->grade_group,
            'payment_period' => $price->payment_period, 'item' => $price->item, 'size' => $price->size,
            'option_type' => $price->option_type, 'option_value' => $price->option_value,
        ], $overrides));
    }

    private function preview(Fee $fee, array $overrides = []): TestResponse
    {
        // Food flexible-duration corrective pass: the price() endpoint
        // requires an explicit duration-mode selection for Food — default
        // every Food preview in this pre-existing suite to a SINGLE day
        // (2026-09-10, a Thursday — a real teaching day under the
        // fri/sat weekly_days_off configured in setUp), so 'amount' stays
        // numerically equal to the tariff's own daily rate, matching what
        // these pre-existing assertions already expect, unless the test
        // itself supplies its own food_* fields.
        $foodDefaults = $fee->category === Fee::CATEGORY_FOOD
            ? ['food_duration_mode' => 'day', 'food_date' => '2026-09-10']
            : [];

        return $this->postJson(route('dashboard.quick-registration.price'), array_replace([
            'fee_id' => $fee->id, 'quantity' => 1, 'academic_year_id' => $this->year->id,
            'grade_id' => $this->grade->id, 'enrollment_mode_id' => $this->mode->id,
            'pricing_date' => '2026-09-10',
        ], $foodDefaults, $overrides));
    }

    private function service(string $name, string $category, string $amount = '0.00'): Fee
    {
        return Fee::create(['name_ru' => $name, 'category' => $category, 'amount' => $amount, 'is_active' => true]);
    }

    private function tariff(Fee $fee, string $amount, array $overrides = []): FeePrice
    {
        $attributes = array_replace([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount,
            'currency' => 'EGP', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ], $overrides);

        return FeePrice::withoutEvents(fn () => FeePrice::create($attributes));
    }
}
