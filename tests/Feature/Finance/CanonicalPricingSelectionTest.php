<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\MealPlan;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use App\Services\Finance\FinanceConfigurationReadinessService;
use App\Services\Finance\InvoiceCalculationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 4A.2 — the approved canonical pricing-selection rule:
 *
 *   1. academic_year_id is the primary ownership boundary for a tariff.
 *   2. A parent may pay for an academic year before classes start.
 *   3. A sole same-fee/same-year/same-dimension tariff candidate is usable
 *      even before its own start_date.
 *   4. start_date/end_date still disambiguate when several same-year
 *      candidates exist (early bird, staged increases, promotions).
 *   5. Never borrow a tariff from another academic year; an ambiguous
 *      pre-window date among several candidates fails clearly.
 *
 * Centralized in InvoiceCalculationService::resolvableCandidates() /
 * selectAmongCandidates() — every entry point (resolver, readiness,
 * previews, Mass Billing, Charge & Collect via InvoiceIssuanceService)
 * composes the same method, never a parallel reimplementation.
 */
class CanonicalPricingSelectionTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;
    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');
        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true]);
    }

    private function fee(string $name, string $category): Fee
    {
        return Fee::create(['name_ru' => $name, 'category' => $category, 'type' => 'service', 'amount' => '0.00', 'is_active' => true]);
    }

    // ----- A. Sole same-year candidate — prepayment ------------------------

    public function test_a_sole_same_year_tariff_is_usable_before_its_own_start_date(): void
    {
        $registration = $this->fee('Регистрационный взнос', Fee::CATEGORY_REGISTRATION);
        FeePrice::create([
            'fee_id' => $registration->id, 'academic_year_id' => $this->year->id, 'amount' => '7000.00', 'currency' => 'EGP',
            'start_date' => '2026-09-01', 'end_date' => $this->year->end_date, 'is_active' => true,
        ]);

        $result = app(InvoiceCalculationService::class)->calculate(
            [['fee_id' => $registration->id, 'quantity' => 1]],
            pricingDate: '2026-08-27',
            academicYearId: $this->year->id,
        );

        $this->assertSame('7000.00', $result['total_amount']);
        $this->assertSame('2026-09-01', $result['line_items'][0]['tariff_valid_from']);
    }

    // ----- B. Prior-year tariff never leaks --------------------------------

    public function test_a_prior_year_tariff_never_leaks_into_the_target_year(): void
    {
        $priorYear = AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => false]);
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $priorYear->id, 'amount' => '6500.00', 'currency' => 'EGP',
            'start_date' => $priorYear->start_date, 'end_date' => $priorYear->end_date, 'grade_group' => '1–4 классы', 'payment_period' => 'yearly', 'is_active' => true,
        ]);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '7200.00', 'currency' => 'EGP',
            'start_date' => '2026-09-01', 'end_date' => $this->year->end_date, 'grade_group' => '1–4 классы', 'payment_period' => 'yearly', 'is_active' => true,
        ]);

        $result = app(InvoiceCalculationService::class)->calculate(
            [['fee_id' => $fee->id, 'quantity' => 1, 'grade_group' => '1–4 классы', 'payment_period' => 'yearly']],
            pricingDate: '2026-08-27',
            academicYearId: $this->year->id,
        );

        $this->assertSame('7200.00', $result['total_amount']);
    }

    public function test_it_never_borrows_a_wrong_years_tariff_even_when_the_target_year_has_none(): void
    {
        $otherYear = AcademicYear::create(['name' => '2027/2028', 'start_date' => '2027-09-01', 'end_date' => '2028-05-31', 'is_active' => false]);
        $fee = $this->fee('Регистрационный взнос', Fee::CATEGORY_REGISTRATION);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '7000.00', 'currency' => 'EGP',
            'start_date' => '2026-09-01', 'end_date' => $this->year->end_date, 'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        app(InvoiceCalculationService::class)->calculate(
            [['fee_id' => $fee->id, 'quantity' => 1]],
            pricingDate: '2026-08-27',
            academicYearId: $otherYear->id,
        );
    }

    // ----- C. Early bird / regular same-year pricing -----------------------

    public function test_early_bird_and_regular_same_year_tariffs_are_disambiguated_by_date(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '6800.00', 'currency' => 'EGP',
            'start_date' => '2026-05-01', 'end_date' => '2026-07-31', 'grade_group' => '1–4 классы', 'payment_period' => 'yearly',
            'change_reason' => 'Льготный тариф при ранней оплате.', 'is_active' => true,
        ]);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '7200.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => $this->year->end_date, 'grade_group' => '1–4 классы', 'payment_period' => 'yearly',
            'change_reason' => 'Основной тариф на учебный год.', 'is_active' => true,
        ]);

        $calculator = app(InvoiceCalculationService::class);
        $selection = [['fee_id' => $fee->id, 'quantity' => 1, 'grade_group' => '1–4 классы', 'payment_period' => 'yearly']];

        $early = $calculator->calculate($selection, pricingDate: '2026-05-15', academicYearId: $this->year->id);
        $this->assertSame('6800.00', $early['total_amount']);

        $regular = $calculator->calculate($selection, pricingDate: '2026-08-15', academicYearId: $this->year->id);
        $this->assertSame('7200.00', $regular['total_amount']);
    }

    // ----- D. Ambiguous pre-window date with several candidates fails ------

    public function test_an_ambiguous_pre_window_date_with_multiple_candidates_fails_clearly(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '6800.00', 'currency' => 'EGP',
            'start_date' => '2026-05-01', 'end_date' => '2026-07-31', 'grade_group' => '1–4 классы', 'payment_period' => 'yearly', 'is_active' => true,
        ]);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '7200.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => $this->year->end_date, 'grade_group' => '1–4 классы', 'payment_period' => 'yearly', 'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        app(InvoiceCalculationService::class)->calculate(
            [['fee_id' => $fee->id, 'quantity' => 1, 'grade_group' => '1–4 классы', 'payment_period' => 'yearly']],
            pricingDate: '2026-04-01',
            academicYearId: $this->year->id,
        );
    }

    // ----- E. Wrong grade/zone/meal-plan/item-size still fails normally ----

    public function test_a_sole_tariff_for_a_different_grade_still_fails_normally(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '7200.00', 'currency' => 'EGP',
            'start_date' => '2026-09-01', 'end_date' => $this->year->end_date, 'grade_group' => '5–6 классы', 'payment_period' => 'yearly', 'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        app(InvoiceCalculationService::class)->calculate(
            [['fee_id' => $fee->id, 'quantity' => 1, 'grade_group' => '1–4 классы', 'payment_period' => 'yearly']],
            pricingDate: '2026-08-27',
            academicYearId: $this->year->id,
        );
    }

    public function test_a_sole_transport_tariff_for_a_different_zone_still_fails_normally(): void
    {
        $transport = $this->fee('Транспорт', Fee::CATEGORY_TRANSPORT);
        FeePrice::create([
            'fee_id' => $transport->id, 'academic_year_id' => $this->year->id, 'amount' => '500.00', 'currency' => 'EGP',
            'start_date' => '2026-09-01', 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'zone', 'option_value' => 'Зона 1',
        ]);

        $this->expectException(ValidationException::class);
        app(InvoiceCalculationService::class)->calculate(
            [['fee_id' => $transport->id, 'quantity' => 1, 'option_type' => 'zone', 'option_value' => 'Зона 2']],
            pricingDate: '2026-08-27',
            academicYearId: $this->year->id,
        );
    }

    public function test_a_sole_food_tariff_for_a_different_meal_plan_still_fails_normally(): void
    {
        $food = $this->fee('Питание', Fee::CATEGORY_FOOD);
        FeePrice::create([
            'fee_id' => $food->id, 'academic_year_id' => $this->year->id, 'amount' => '300.00', 'currency' => 'EGP',
            'start_date' => '2026-09-01', 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => '1',
        ]);

        $this->expectException(ValidationException::class);
        app(InvoiceCalculationService::class)->calculate(
            [['fee_id' => $food->id, 'quantity' => 1, 'option_type' => 'meal_plan', 'option_value' => '2']],
            pricingDate: '2026-08-27',
            academicYearId: $this->year->id,
        );
    }

    public function test_a_sole_uniform_tariff_for_a_different_item_size_still_fails_normally(): void
    {
        $uniform = $this->fee('Футболка', Fee::CATEGORY_UNIFORM);
        FeePrice::create([
            'fee_id' => $uniform->id, 'academic_year_id' => $this->year->id, 'amount' => '200.00', 'currency' => 'EGP',
            'start_date' => '2026-09-01', 'end_date' => $this->year->end_date, 'is_active' => true,
            'item' => 'Футболка', 'size' => 'M',
        ]);

        $this->expectException(ValidationException::class);
        app(InvoiceCalculationService::class)->calculate(
            [['fee_id' => $uniform->id, 'quantity' => 1, 'item' => 'Футболка', 'size' => 'L']],
            pricingDate: '2026-08-27',
            academicYearId: $this->year->id,
        );
    }

    // ----- F. Preview == persisted, dimension parity ------------------------

    public function test_quick_registration_preview_matches_the_persisted_invoice_amount(): void
    {
        $stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $grade = Grade::create(['name' => '1 класс', 'stage_id' => $stage->id]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => '1-А', 'name_ar' => '1-A', 'name_ru' => '1-А', 'is_active' => true]);
        $mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очное обучение', 'is_active' => true]);
        $account = CashAccount::operating();
        app(\App\Services\Finance\CashSessionService::class)->open($account, $this->accountant);

        $registration = $this->fee('Регистрационный взнос', Fee::CATEGORY_REGISTRATION);
        FeePrice::create([
            'fee_id' => $registration->id, 'academic_year_id' => $this->year->id, 'amount' => '7000.00', 'currency' => 'EGP',
            'start_date' => '2026-09-01', 'end_date' => $this->year->end_date, 'is_active' => true,
        ]);

        $preview = $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $registration->id, 'quantity' => 1, 'academic_year_id' => $this->year->id,
            'enrollment_mode_id' => $mode->id, 'registration_date' => '2026-08-27',
        ])->assertOk()->json();
        $this->assertSame('7000.00', $preview['amount']);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), [
            'student_last_name_ru' => 'Иванов', 'student_first_name_ru' => 'Иван', 'phone' => '01012345678',
            'academic_year_id' => $this->year->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id,
            'enrollment_mode_id' => $mode->id, 'registration_date' => '2026-08-27',
            'services' => [['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00']],
            'cash_account_id' => $account->id, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertSame('7000.00', Invoice::sole()->total_amount);
    }

    // ----- Readiness agrees with the resolver --------------------------------

    public function test_readiness_agrees_with_the_resolver_for_a_sole_pre_start_tariff(): void
    {
        $registration = $this->fee('Регистрационный взнос', Fee::CATEGORY_REGISTRATION);
        FeePrice::create([
            'fee_id' => $registration->id, 'academic_year_id' => $this->year->id, 'amount' => '7000.00', 'currency' => 'EGP',
            'start_date' => '2026-09-01', 'end_date' => $this->year->end_date, 'is_active' => true,
        ]);

        $readinessResult = app(FinanceConfigurationReadinessService::class)->forFee($registration, $this->year);
        $this->assertTrue($readinessResult['ready']);

        // The resolver itself must agree — proving the two can never drift.
        $resolverResult = app(InvoiceCalculationService::class)->calculate(
            [['fee_id' => $registration->id, 'quantity' => 1]],
            pricingDate: '2026-08-27',
            academicYearId: $this->year->id,
        );
        $this->assertSame('7000.00', $resolverResult['total_amount']);
    }

    public function test_readiness_agrees_with_the_resolver_for_an_ambiguous_pre_window_case(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '6800.00', 'currency' => 'EGP',
            'start_date' => '2026-05-01', 'end_date' => '2026-07-31', 'grade_group' => '1–4 классы', 'payment_period' => 'yearly', 'is_active' => true,
        ]);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '7200.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => $this->year->end_date, 'grade_group' => '1–4 классы', 'payment_period' => 'yearly', 'is_active' => true,
        ]);

        // Readiness deliberately checks "is there ANY resolvable candidate
        // right now" — with today after the early-bird window opened, the
        // category is genuinely ready; the resolver would agree for that
        // same date. This documents the two are never computed independently.
        $result = app(FinanceConfigurationReadinessService::class)->forFee($fee, $this->year);
        $this->assertSame(
            $result['ready'],
            app(InvoiceCalculationService::class)->resolvableCandidates(
                FeePrice::where('fee_id', $fee->id)->get(),
                now()->toDateString(),
            )->isNotEmpty(),
        );
    }

    public function test_transport_food_uniform_readiness_all_honor_the_sole_candidate_exemption(): void
    {
        $transport = $this->fee('Транспорт', Fee::CATEGORY_TRANSPORT);
        FeePrice::create([
            'fee_id' => $transport->id, 'academic_year_id' => $this->year->id, 'amount' => '500.00', 'currency' => 'EGP',
            'start_date' => '2026-09-01', 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'zone', 'option_value' => 'Зона 1',
        ]);
        DB::table('transport_routes')->insert(['name' => 'Маршрут 1', 'created_at' => now(), 'updated_at' => now()]);
        $food = $this->fee('Питание', Fee::CATEGORY_FOOD);
        $mealPlan = MealPlan::create(['name_ru' => 'Обед', 'meal_type' => MealPlan::TYPE_LUNCH, 'period' => MealPlan::PERIOD_MONTHLY, 'price' => '300.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $food->id, 'academic_year_id' => $this->year->id, 'amount' => '300.00', 'currency' => 'EGP',
            'start_date' => '2026-09-01', 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => (string) $mealPlan->id,
        ]);
        $uniform = $this->fee('Футболка', Fee::CATEGORY_UNIFORM);
        FeePrice::create([
            'fee_id' => $uniform->id, 'academic_year_id' => $this->year->id, 'amount' => '200.00', 'currency' => 'EGP',
            'start_date' => '2026-09-01', 'end_date' => $this->year->end_date, 'is_active' => true,
            'item' => 'Футболка', 'size' => 'M',
        ]);
        DB::table('uniform_products')->insert(['name_ru' => 'Футболка', 'category' => 'shirt', 'size' => 'M', 'price' => 200, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $service = app(FinanceConfigurationReadinessService::class);
        $this->assertTrue($service->forFee($transport, $this->year)['ready']);
        $this->assertTrue($service->forFee($food, $this->year)['ready']);
        $this->assertTrue($service->forFee($uniform, $this->year)['ready']);
    }

    // ----- No financial mutation during readiness ----------------------------

    public function test_no_financial_mutation_occurs_during_a_readiness_check(): void
    {
        $registration = $this->fee('Регистрационный взнос', Fee::CATEGORY_REGISTRATION);
        FeePrice::create([
            'fee_id' => $registration->id, 'academic_year_id' => $this->year->id, 'amount' => '7000.00', 'currency' => 'EGP',
            'start_date' => '2026-09-01', 'end_date' => $this->year->end_date, 'is_active' => true,
        ]);

        $countsBefore = [Fee::count(), FeePrice::count(), Invoice::count(), AcademicYear::count()];

        app(FinanceConfigurationReadinessService::class)->forAcademicYear($this->year);

        $this->assertSame($countsBefore, [Fee::count(), FeePrice::count(), Invoice::count(), AcademicYear::count()]);
    }
}
