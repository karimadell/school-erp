<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\MealPlan;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Services\Finance\FinanceConfigurationReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 — the reusable readiness layer. Every assertion here mirrors a
 * concrete UAT symptom from the Pricing & Master Data Audit: a stale prior
 * year price, an inactive tariff, a missing meal plan, a missing uniform
 * tariff, a missing transport zone tariff, zero installment plans. This is
 * the single place those checks live — Quick Registration's blade only
 * consumes the result (see QuickRegistrationAvailabilityGatingTest).
 */
class FinanceConfigurationReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;
    private FinanceConfigurationReadinessService $readiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->readiness = app(FinanceConfigurationReadinessService::class);
    }

    private function fee(string $name, string $category): Fee
    {
        return Fee::create(['name_ru' => $name, 'category' => $category, 'amount' => '1.00', 'is_active' => true]);
    }

    private function price(Fee $fee, array $overrides = []): FeePrice
    {
        return FeePrice::create(array_merge([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '100.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
        ], $overrides));
    }

    // ----- Transport -----------------------------------------------------

    public function test_transport_is_ready_when_a_zone_tariff_exists(): void
    {
        $fee = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        $this->price($fee, ['option_type' => 'zone', 'option_value' => 'Зона 1']);

        $result = $this->readiness->forFee($fee, $this->year);

        $this->assertTrue($result['ready']);
        $this->assertNull($result['reason']);
    }

    public function test_transport_is_not_ready_without_any_tariff(): void
    {
        $fee = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);

        $result = $this->readiness->forFee($fee, $this->year);

        $this->assertFalse($result['ready']);
        $this->assertNotNull($result['reason']);
        $this->assertStringContainsString('транспортной зоны', $result['reason']);
    }

    public function test_transport_is_not_ready_when_the_only_tariff_has_the_wrong_option_type(): void
    {
        // Exactly the confirmed importer bug from Phase 1 (option_type='Район'
        // instead of 'zone') — a tariff exists, but it's unresolvable.
        $fee = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        $this->price($fee, ['option_type' => 'Район', 'option_value' => 'Зона 1']);

        $this->assertFalse($this->readiness->forFee($fee, $this->year)['ready']);
    }

    // ----- Food ------------------------------------------------------------

    public function test_food_is_ready_when_a_meal_plan_backed_tariff_exists(): void
    {
        $fee = $this->fee('Питание', Fee::CATEGORY_FOOD);
        $plan = MealPlan::create(['name_ru' => 'Завтрак', 'meal_type' => 'breakfast', 'period' => 'daily', 'price' => '70.00', 'is_active' => true]);
        $this->price($fee, ['option_type' => 'meal_plan', 'option_value' => (string) $plan->id]);

        $this->assertTrue($this->readiness->forFee($fee, $this->year)['ready']);
    }

    public function test_food_is_not_ready_when_a_meal_plan_exists_but_has_no_tariff(): void
    {
        $fee = $this->fee('Питание', Fee::CATEGORY_FOOD);
        MealPlan::create(['name_ru' => 'Завтрак', 'meal_type' => 'breakfast', 'period' => 'daily', 'price' => '70.00', 'is_active' => true]);

        $result = $this->readiness->forFee($fee, $this->year);
        $this->assertFalse($result['ready']);
        $this->assertStringContainsString('плана питания', $result['reason']);
    }

    // ----- Uniform ---------------------------------------------------------

    public function test_uniform_is_ready_when_an_item_and_size_tariff_exists(): void
    {
        $fee = $this->fee('Школьная форма', Fee::CATEGORY_UNIFORM);
        $this->price($fee, ['item' => 'Комплект', 'size' => '6-10']);

        $this->assertTrue($this->readiness->forFee($fee, $this->year)['ready']);
    }

    public function test_uniform_is_not_ready_without_any_priced_item_size_combination(): void
    {
        $fee = $this->fee('Школьная форма', Fee::CATEGORY_UNIFORM);

        $result = $this->readiness->forFee($fee, $this->year);
        $this->assertFalse($result['ready']);
        $this->assertStringContainsString('школьную форму', $result['reason']);
    }

    // ----- Tuition / Registration -------------------------------------------

    public function test_tuition_is_ready_when_any_sellable_price_exists(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION);
        $this->price($fee, ['grade_group' => '1–4 классы', 'payment_period' => 'yearly']);

        $this->assertTrue($this->readiness->forFee($fee, $this->year)['ready']);
    }

    public function test_registration_is_ready_when_any_sellable_price_exists(): void
    {
        $fee = $this->fee('Организационный взнос', Fee::CATEGORY_REGISTRATION);
        $this->price($fee);

        $this->assertTrue($this->readiness->forFee($fee, $this->year)['ready']);
    }

    public function test_tuition_is_not_ready_with_zero_prices(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION);

        $this->assertFalse($this->readiness->forFee($fee, $this->year)['ready']);
    }

    // ----- Stale / wrong-year / inactive / wrong-currency -------------------

    public function test_a_stale_prior_year_price_does_not_count_as_ready(): void
    {
        $oldYear = AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => false]);
        $fee = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $oldYear->id, 'amount' => '100.00', 'currency' => 'EGP',
            'start_date' => $oldYear->start_date, 'end_date' => $oldYear->end_date, 'is_active' => true,
            'option_type' => 'zone', 'option_value' => 'Зона 1',
        ]);

        $this->assertFalse($this->readiness->forFee($fee, $this->year)['ready']);
    }

    public function test_an_inactive_price_does_not_count_as_ready(): void
    {
        $fee = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        $this->price($fee, ['option_type' => 'zone', 'option_value' => 'Зона 1', 'is_active' => false]);

        $this->assertFalse($this->readiness->forFee($fee, $this->year)['ready']);
    }

    public function test_a_price_dated_outside_the_current_window_does_not_count_as_ready(): void
    {
        $fee = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        // A tariff whose validity window closed long before "now" —
        // sellable()/current() must exclude it, even though it belongs to
        // the right fee and the right academic year.
        $this->price($fee, ['option_type' => 'zone', 'option_value' => 'Зона 1', 'start_date' => '2020-01-01', 'end_date' => '2020-01-31']);

        $this->assertFalse($this->readiness->forFee($fee, $this->year)['ready']);
    }

    public function test_a_sole_price_not_yet_valid_still_counts_as_ready(): void
    {
        // Phase 4A.2 canonical rule: academic_year_id is the primary
        // ownership boundary for a tariff, not the calendar date — a sole
        // same-year candidate is ready even before its own start_date
        // (prepayment), exactly what InvoiceCalculationService's resolver
        // itself would resolve for this same selection.
        $fee = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        $this->price($fee, ['option_type' => 'zone', 'option_value' => 'Зона 1', 'start_date' => '2027-05-01', 'end_date' => '2027-06-30']);

        $this->assertTrue($this->readiness->forFee($fee, $this->year)['ready']);
    }

    public function test_an_ambiguous_pre_window_price_among_several_same_year_candidates_does_not_count_as_ready(): void
    {
        $fee = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        // Two same-year, same-zone candidates (early period + later period),
        // with "today" (year start) before either window opens.
        $this->price($fee, ['option_type' => 'zone', 'option_value' => 'Зона 1', 'start_date' => '2027-01-01', 'end_date' => '2027-03-31']);
        $this->price($fee, ['option_type' => 'zone', 'option_value' => 'Зона 1', 'start_date' => '2027-05-01', 'end_date' => '2027-06-30']);

        $this->assertFalse($this->readiness->forFee($fee, $this->year)['ready']);
    }

    // ----- Installments ------------------------------------------------------

    public function test_installments_are_ready_when_an_active_plan_exists(): void
    {
        $plan = PaymentPlan::create(['name_ru' => 'План', 'is_active' => true, 'sort_order' => 1]);
        PaymentPlanInstallment::create(['payment_plan_id' => $plan->id, 'name_ru' => 'Этап 1', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100.0000']);

        $result = $this->readiness->installments();
        $this->assertTrue($result['ready']);
        $this->assertNull($result['reason']);
    }

    public function test_installments_are_not_ready_with_zero_active_plans(): void
    {
        $result = $this->readiness->installments();

        $this->assertFalse($result['ready']);
        $this->assertSame('Нет активных планов рассрочки.', $result['reason']);
    }

    public function test_an_inactive_payment_plan_does_not_count_as_ready(): void
    {
        PaymentPlan::create(['name_ru' => 'Отключённый план', 'is_active' => false, 'sort_order' => 1]);

        $this->assertFalse($this->readiness->installments()['ready']);
    }

    // ----- Rollup ------------------------------------------------------------

    public function test_for_academic_year_reports_all_six_categories(): void
    {
        $result = $this->readiness->forAcademicYear($this->year);

        $this->assertSame(['tuition', 'registration', 'transport', 'food', 'uniform', 'installments'], array_keys($result));
        foreach ($result as $category => $status) {
            $this->assertArrayHasKey('ready', $status, "category {$category} missing 'ready'");
            $this->assertArrayHasKey('reason', $status, "category {$category} missing 'reason'");
            $this->assertFalse($status['ready'], "category {$category} should be NOT READY with zero master data");
        }
    }

    public function test_for_academic_year_reports_ready_once_a_category_has_a_configured_fee(): void
    {
        $fee = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        $this->price($fee, ['option_type' => 'zone', 'option_value' => 'Зона 1']);

        $result = $this->readiness->forAcademicYear($this->year);

        $this->assertTrue($result['transport']['ready']);
        $this->assertFalse($result['food']['ready']);
    }

    // ----- Batch (forFees) parity with the single-fee path -------------------

    public function test_for_fees_matches_for_fee_for_every_fee_in_the_batch(): void
    {
        $ready = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        $this->price($ready, ['option_type' => 'zone', 'option_value' => 'Зона 1']);
        $notReady = $this->fee('Питание', Fee::CATEGORY_FOOD);

        $batch = $this->readiness->forFees(collect([$ready, $notReady]), $this->year);

        $this->assertTrue($batch[$ready->id]['ready']);
        $this->assertFalse($batch[$notReady->id]['ready']);
        $this->assertSame($this->readiness->forFee($ready, $this->year), $batch[$ready->id]);
        $this->assertSame($this->readiness->forFee($notReady, $this->year), $batch[$notReady->id]);
    }

    // ----- Read-only guarantee -------------------------------------------------

    public function test_readiness_checks_never_mutate_any_data(): void
    {
        $transport = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        $this->price($transport, ['option_type' => 'zone', 'option_value' => 'Зона 1']);
        $food = $this->fee('Питание', Fee::CATEGORY_FOOD);

        $countsBefore = [Fee::count(), FeePrice::count(), PaymentPlan::count(), Invoice::count(), AcademicYear::count()];

        $this->readiness->forFee($transport, $this->year);
        $this->readiness->forFee($food, $this->year);
        $this->readiness->forFees(collect([$transport, $food]), $this->year);
        $this->readiness->forAcademicYear($this->year);
        $this->readiness->installments();

        $this->assertSame($countsBefore, [Fee::count(), FeePrice::count(), PaymentPlan::count(), Invoice::count(), AcademicYear::count()]);
    }
}
