<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Services\Finance\InvoiceCalculationService;
use App\Services\Finance\MassBillingEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * P0 corrective: InvoiceCalculationService::dimensionalCandidates() only
 * checked $hasModePrices inside an `elseif (filled(enrollment_mode_id))`
 * branch — a blank enrollment_mode_id skipped the check entirely, so once
 * ANY mode-scoped FeePrice existed in an exact (fee, year, grade,
 * payment_period) scope, the query stayed unfiltered on option_type/
 * option_value and could silently resolve to whichever EnrollmentMode's
 * tariff happened to sort first. These tests prove a blank
 * enrollment_mode_id can never silently receive a mode-specific tariff —
 * including when only ONE mode-scoped row exists — while every other
 * existing resolution behavior (generic-only pricing, mode-supplied
 * pricing, cross-scope isolation, non-Tuition dimensional pricing) is
 * unchanged.
 */
class TuitionModePricingGuardTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceCalculationService $service;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InvoiceCalculationService::class);
        $this->year = AcademicYear::create([
            'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
    }

    private function tuition(): Fee
    {
        return Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => true]);
    }

    private function price(Fee $fee, string $gradeGroup, string $paymentPeriod, string $amount, ?string $modeCode = null): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $fee->id,
            'academic_year_id' => $this->year->id,
            'grade_group' => $gradeGroup,
            'payment_period' => $paymentPeriod,
            'option_type' => $modeCode ? 'enrollment_mode' : null,
            'option_value' => $modeCode,
            'amount' => $amount,
            'currency' => 'EGP',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);
    }

    private function item(Fee $fee, string $gradeGroup, string $paymentPeriod, ?int $modeId = null): array
    {
        return array_filter([
            'fee_id' => $fee->id,
            'grade_group' => $gradeGroup,
            'payment_period' => $paymentPeriod,
            'enrollment_mode_id' => $modeId,
        ], fn ($value) => $value !== null);
    }

    // 1. NULL enrollment_mode_id + generic-only price resolves normally.
    public function test_null_mode_with_only_generic_price_resolves_normally(): void
    {
        $fee = $this->tuition();
        $this->price($fee, '1–4 классы', 'yearly', '40500.00');

        $result = $this->service->calculate(
            [$this->item($fee, '1–4 классы', 'yearly')],
            academicYearId: $this->year->id, pricingDate: '2026-09-10',
        );

        $this->assertSame('40500.00', $result['subtotal']);
    }

    // 2. NULL enrollment_mode_id + multiple mode-scoped prices fails loudly.
    public function test_null_mode_with_multiple_mode_scoped_prices_fails_loudly(): void
    {
        $fee = $this->tuition();
        $this->price($fee, '1–4 классы', 'yearly', '40500.00', 'full_time');
        $this->price($fee, '1–4 классы', 'yearly', '25600.00', 'external');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('тариф зависит от формы обучения');

        $this->service->calculate(
            [$this->item($fee, '1–4 классы', 'yearly')],
            academicYearId: $this->year->id, pricingDate: '2026-09-10',
        );
    }

    // 3. NULL enrollment_mode_id + ONE mode-scoped price must NOT silently
    // resolve to it — the critical case: a single row is exactly as unsafe
    // as several.
    public function test_null_mode_with_a_single_mode_scoped_price_fails_loudly_rather_than_silently_using_it(): void
    {
        $fee = $this->tuition();
        $this->price($fee, '1–4 классы', 'yearly', '25600.00', 'external');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('тариф зависит от формы обучения');

        $this->service->calculate(
            [$this->item($fee, '1–4 классы', 'yearly')],
            academicYearId: $this->year->id, pricingDate: '2026-09-10',
        );
    }

    // 4. NULL enrollment_mode_id + generic AND mode-scoped coexisting in the
    // same scope must still fail loudly — the generic row's presence must
    // never be used as an excuse to silently pick either price.
    public function test_null_mode_with_generic_and_mode_scoped_coexisting_fails_loudly(): void
    {
        $fee = $this->tuition();
        $this->price($fee, '1–4 классы', 'yearly', '40500.00');
        $this->price($fee, '1–4 классы', 'yearly', '25600.00', 'external');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('тариф зависит от формы обучения');

        $this->service->calculate(
            [$this->item($fee, '1–4 классы', 'yearly')],
            academicYearId: $this->year->id, pricingDate: '2026-09-10',
        );
    }

    // 5 & 6. A supplied mode resolves its own price correctly — unchanged
    // existing behavior, re-verified alongside the new guard.
    public function test_supplied_full_time_and_external_modes_resolve_their_own_prices(): void
    {
        $fee = $this->tuition();
        $fullTime = EnrollmentMode::create(['code' => 'full_time', 'name_ru' => 'Очная форма обучения', 'is_active' => true]);
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false]);
        $this->price($fee, '1–4 классы', 'yearly', '40500.00', 'full_time');
        $this->price($fee, '1–4 классы', 'yearly', '25600.00', 'external');

        $fullTimeResult = $this->service->calculate(
            [$this->item($fee, '1–4 классы', 'yearly', $fullTime->id)],
            academicYearId: $this->year->id, pricingDate: '2026-09-10',
        );
        $this->assertSame('40500.00', $fullTimeResult['subtotal']);

        $externalResult = $this->service->calculate(
            [$this->item($fee, '1–4 классы', 'yearly', $external->id)],
            academicYearId: $this->year->id, pricingDate: '2026-09-10',
        );
        $this->assertSame('25600.00', $externalResult['subtotal']);
    }

    // 7. A supplied mode with no price configured for it preserves the
    // existing, unrelated fail-loud behavior (not this PR's new message).
    public function test_supplied_mode_without_a_configured_price_still_fails_loudly_with_the_existing_message(): void
    {
        $fee = $this->tuition();
        $fullTime = EnrollmentMode::create(['code' => 'full_time', 'name_ru' => 'Очная форма обучения', 'is_active' => true]);
        $family = EnrollmentMode::create(['code' => 'family', 'name_ru' => 'Семейная форма обучения', 'is_active' => false]);
        $this->price($fee, '1–4 классы', 'yearly', '40500.00', 'full_time');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('На выбранную дату тариф не настроен.');

        $this->service->calculate(
            [$this->item($fee, '1–4 классы', 'yearly', $family->id)],
            academicYearId: $this->year->id, pricingDate: '2026-09-10',
        );
    }

    // 8. payment_period scopes remain isolated: a mode-scoped price in
    // "monthly" must not affect NULL-mode resolution in "yearly" for the
    // same fee/grade, and vice versa.
    public function test_payment_period_scopes_remain_isolated_for_the_null_mode_guard(): void
    {
        $fee = $this->tuition();
        $this->price($fee, '1–4 классы', 'yearly', '40500.00'); // generic only
        $this->price($fee, '1–4 классы', 'monthly', '3200.00', 'external'); // mode-scoped only

        $yearlyResult = $this->service->calculate(
            [$this->item($fee, '1–4 классы', 'yearly')],
            academicYearId: $this->year->id, pricingDate: '2026-09-10',
        );
        $this->assertSame('40500.00', $yearlyResult['subtotal']);

        $this->expectException(ValidationException::class);
        $this->service->calculate(
            [$this->item($fee, '1–4 классы', 'monthly')],
            academicYearId: $this->year->id, pricingDate: '2026-09-10',
        );
    }

    // 9. grade_group scopes remain isolated: a mode-scoped price in one
    // grade group must not affect NULL-mode resolution in a different one.
    public function test_grade_group_scopes_remain_isolated_for_the_null_mode_guard(): void
    {
        $fee = $this->tuition();
        $this->price($fee, '5–6 классы', 'yearly', '49500.00'); // generic only
        $this->price($fee, '1–4 классы', 'yearly', '25600.00', 'external'); // mode-scoped only

        $result = $this->service->calculate(
            [$this->item($fee, '5–6 классы', 'yearly')],
            academicYearId: $this->year->id, pricingDate: '2026-09-10',
        );
        $this->assertSame('49500.00', $result['subtotal']);

        $this->expectException(ValidationException::class);
        $this->service->calculate(
            [$this->item($fee, '1–4 классы', 'yearly')],
            academicYearId: $this->year->id, pricingDate: '2026-09-10',
        );
    }

    // 10. Non-Tuition dimensional pricing (e.g. Transport's zone dimension)
    // must never trigger this guard — MODE_OPTION_TYPES never includes
    // 'zone', so $hasModePrices is always false there, with or without an
    // enrollment_mode_id.
    public function test_unrelated_dimensional_pricing_such_as_transport_zone_is_unaffected(): void
    {
        $fee = Fee::create(['name_ru' => 'Транспорт', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '0.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'option_type' => 'zone', 'option_value' => 'Зона 1',
            'amount' => '1500.00', 'currency' => 'EGP', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);

        $result = $this->service->calculate(
            [['fee_id' => $fee->id, 'option_type' => 'zone', 'option_value' => 'Зона 1']],
            academicYearId: $this->year->id, pricingDate: '2026-09-10',
        );

        $this->assertSame('1500.00', $result['subtotal']);
    }

    // Mass Billing regression: a NULL-mode Enrollment hitting a mode-scoped
    // Tuition scope must be skipped gracefully (SKIP_NO_TARIFF) — never a
    // batch-halting exception, and never a silently-wrong charged amount.
    // MassBillingEligibilityService::classify() already wraps calculate()
    // in a try/catch(ValidationException); this proves the new guard's
    // exception lands in that existing, safe path.
    public function test_mass_billing_skips_a_null_mode_enrollment_gracefully_instead_of_mispricing_it(): void
    {
        $stage = Stage::create(['name' => 'Начальная', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $stage->id, 'level' => 1]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'А', 'name_ru' => '1-А', 'name_ar' => 'A', 'is_active' => true]);
        $fee = $this->tuition();
        $this->price($fee, '1–4 классы', 'yearly', '40500.00', 'full_time');
        $this->price($fee, '1–4 классы', 'yearly', '25600.00', 'external');

        $student = Student::create(['name' => 'Студент Б/З']);
        $enrollment = Enrollment::create([
            'student_id' => $student->id, 'academic_year_id' => $this->year->id, 'enrollment_mode_id' => null,
            'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id, 'academic_year' => $this->year->name,
            'enrollment_date' => '2026-09-01', 'enrolled_at' => '2026-09-01', 'status' => 'active', 'is_active' => true,
        ]);

        $result = app(MassBillingEligibilityService::class)->classify(
            enrollment: $enrollment, fee: $fee, yearActive: true, feeActive: true, registrationDuplicate: false,
            quantity: 1, pricingDate: '2026-09-10', academicYearId: $this->year->id,
        );

        $this->assertFalse($result['eligible']);
        $this->assertSame(MassBillingEligibilityService::SKIP_NO_TARIFF, $result['skip_reason']);
        $this->assertNull($result['amount']);
    }
}
