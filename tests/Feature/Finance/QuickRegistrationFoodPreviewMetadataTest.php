<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicCalendar;
use App\Models\Fee;
use App\Models\FeePrice;
use Illuminate\Testing\TestResponse;

/**
 * Pre-Premium-UI corrective pass — Decision 3.
 *
 * The /price live-preview endpoint's JSON response must expose
 * billable_day_count/coverage_start/coverage_end for Food — read directly
 * from the SAME FoodBillableDayCalculator result InvoiceCalculationService
 * itself produces, never recalculated separately — and stay consistent
 * with the 'amount' field the endpoint already returned (amount must equal
 * the per-day rate times billable_day_count, since every configured Food
 * tariff in these tests charges a single flat daily rate). Non-Food
 * services must never populate these three fields.
 */
class QuickRegistrationFoodPreviewMetadataTest extends QuickRegistrationUxTestCase
{
    private \App\Models\AcademicYear $year;

    private \App\Models\Grade $grade;

    private \App\Models\EnrollmentMode $mode;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->year, , $this->grade, , $this->mode] = $this->structure();
        $this->year->update(['start_date' => '2026-09-01', 'end_date' => '2027-05-31']);
        // fri/sat off — 2026-09-06 is a Sunday, 2026-09-10 a Thursday
        // (established teaching days), matching this project's own
        // MultiServiceInvoicePricePreviewTest fixture convention.
        AcademicCalendar::create(['academic_year_id' => $this->year->id, 'weekly_days_off' => ['fri', 'sat']]);
        $this->actingAs($this->accountant);
    }

    private function foodFee(): Fee
    {
        return Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '0.00', 'is_active' => true]);
    }

    private function foodTariff(Fee $fee, string $amount, string $start = '2026-09-01', string $end = '2027-05-31'): FeePrice
    {
        return FeePrice::withoutEvents(fn () => FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP',
            'start_date' => $start, 'end_date' => $end, 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => 'Комплексное питание', 'payment_period' => 'daily',
        ]));
    }

    private function preview(Fee $fee, array $overrides = []): TestResponse
    {
        return $this->postJson(route('dashboard.quick-registration.price'), array_replace([
            'fee_id' => $fee->id, 'quantity' => 1, 'academic_year_id' => $this->year->id,
            'grade_id' => $this->grade->id, 'enrollment_mode_id' => $this->mode->id,
            'pricing_date' => '2026-09-10',
        ], $overrides));
    }

    private function assertConsistentFoodPreview(TestResponse $response, string $dailyRate, string $expectedStart, string $expectedEnd): void
    {
        $response->assertOk();
        $count = $response->json('billable_day_count');
        $this->assertIsInt($count);
        $this->assertGreaterThan(0, $count);
        $this->assertSame($expectedStart, $response->json('coverage_start'));
        $this->assertSame($expectedEnd, $response->json('coverage_end'));
        $this->assertSame(bcmul($dailyRate, (string) $count, 2), $response->json('amount'));
    }

    public function test_one_day(): void
    {
        $fee = $this->foodFee();
        $this->foodTariff($fee, '170.00');

        $response = $this->preview($fee, ['food_duration_mode' => 'day', 'food_date' => '2026-09-10']);

        $this->assertConsistentFoodPreview($response, '170.00', '2026-09-10', '2026-09-10');
        $this->assertSame(1, $response->json('billable_day_count'));
    }

    public function test_school_week(): void
    {
        $fee = $this->foodFee();
        $this->foodTariff($fee, '170.00');

        $response = $this->preview($fee, ['food_duration_mode' => 'school_week', 'food_week_start' => '2026-09-06']);

        // 2026-09-06 (Sun) .. 2026-09-12 (Sat): Sun-Thu teaching, Fri/Sat off = 5.
        $this->assertConsistentFoodPreview($response, '170.00', '2026-09-06', '2026-09-12');
        $this->assertSame(5, $response->json('billable_day_count'));
    }

    public function test_n_teaching_days(): void
    {
        $fee = $this->foodFee();
        $this->foodTariff($fee, '170.00');

        $response = $this->preview($fee, ['food_duration_mode' => 'teaching_days', 'food_start_date' => '2026-09-06', 'food_day_count' => 5]);

        // Forward from Sunday 2026-09-06, 5 teaching days = through Thursday 2026-09-10.
        $this->assertConsistentFoodPreview($response, '170.00', '2026-09-06', '2026-09-10');
        $this->assertSame(5, $response->json('billable_day_count'));
    }

    public function test_one_month(): void
    {
        $fee = $this->foodFee();
        $this->foodTariff($fee, '170.00');

        $response = $this->preview($fee, ['food_duration_mode' => 'month', 'food_month' => '2026-09']);

        $this->assertConsistentFoodPreview($response, '170.00', '2026-09-01', '2026-09-30');
    }

    public function test_multiple_months(): void
    {
        $fee = $this->foodFee();
        $this->foodTariff($fee, '170.00');

        $response = $this->preview($fee, ['food_duration_mode' => 'month', 'food_month' => '2026-09', 'food_end_month' => '2026-10']);

        $this->assertConsistentFoodPreview($response, '170.00', '2026-09-01', '2026-10-31');
    }

    public function test_custom_range(): void
    {
        $fee = $this->foodFee();
        $this->foodTariff($fee, '170.00');

        $response = $this->preview($fee, ['food_duration_mode' => 'custom_range', 'food_range_start' => '2026-09-14', 'food_range_end' => '2026-09-24']);

        $this->assertConsistentFoodPreview($response, '170.00', '2026-09-14', '2026-09-24');
    }

    public function test_tariff_effective_date_boundary_mid_range(): void
    {
        $fee = $this->foodFee();
        // Two adjacent, non-overlapping tariff windows for the SAME
        // dimension (meal_plan / "Комплексное питание") — the segmented
        // per-day pricing this range crosses.
        $this->foodTariff($fee, '100.00', '2026-09-01', '2026-09-15');
        $this->foodTariff($fee, '120.00', '2026-09-16', '2027-05-31');

        // 2026-09-14 (Mon) .. 2026-09-17 (Thu): all 4 teaching days.
        // 14, 15 @ 100.00 = 200.00 ; 16, 17 @ 120.00 = 240.00 ; total 440.00.
        $response = $this->preview($fee, ['food_duration_mode' => 'custom_range', 'food_range_start' => '2026-09-14', 'food_range_end' => '2026-09-17']);

        $response->assertOk();
        $this->assertSame('440.00', $response->json('amount'));
        $this->assertSame(4, $response->json('billable_day_count'));
        $this->assertSame('2026-09-14', $response->json('coverage_start'));
        $this->assertSame('2026-09-17', $response->json('coverage_end'));
    }

    public function test_non_food_service_returns_null_for_food_only_fields(): void
    {
        $fee = Fee::create(['name_ru' => 'Организационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '7000.00', 'is_active' => true]);

        $response = $this->preview($fee);

        $response->assertOk();
        $this->assertNull($response->json('billable_day_count'));
        $this->assertNull($response->json('coverage_start'));
        $this->assertNull($response->json('coverage_end'));
    }
}
