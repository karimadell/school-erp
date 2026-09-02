<?php

namespace Tests\Feature\Finance;

use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Services\Finance\InvoiceIssuanceService;
use Illuminate\Validation\ValidationException;

/**
 * Finance V2, Phase 2D corrective pass #2 (HIGH 5 — complete, shared
 * canonical tariff dimension set).
 *
 * resolveCoverageBasisPrice() previously received only a hand-picked
 * subset of dimensions (grade_group/option_type/option_value/size/item —
 * missing grade_id and enrollment_mode_id entirely), so a quarterly/
 * yearly/Food adjustment-basis lookup could silently disagree with
 * primary price resolution about which tariff actually applies whenever
 * two candidate basis prices differed only by grade or enrollment mode.
 * Fixed by passing the item's FULL selection/metadata through, reusing
 * the identical dimensionalCandidates() matching primary resolution
 * already uses. These tests prove the fix with genuinely COMPETING
 * candidates (not just one tariff with the field set, which could pass
 * even with the old, incomplete dimension subset).
 */
class CanonicalTariffDimensionMatchingTest extends FinanceOperationsTestCase
{
    public function test_a_quarterly_adjustment_basis_lookup_picks_the_correct_grade_never_a_competing_grades_price(): void
    {
        $otherGrade = Grade::forceCreate(['name' => 'Другая параллель', 'stage_id' => $this->enrollment->grade->stage_id, 'level' => 99]);

        $fee = Fee::create(['name_ru' => 'Обучение (квартал, конкурирующие тарифы)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'quarterly']);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);

        // Two monthly tariffs differing ONLY by grade_id — the enrollment
        // is in $this->enrollment->grade_id; the wrong one belongs to a
        // completely different grade.
        $correct = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $otherGrade->id, 'amount' => '9999.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'quarterly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'calendar', 'billing_period' => 'quarterly',
        ], $this->accountant);

        $item = $invoice->items->sole();
        $this->assertSame($correct->id, $item->metadata['derived_from_fee_price_id']);
        $this->assertSame('1000.00', $item->metadata['monthly_unit_amount']);
        $coverage = \App\Models\ServiceCoverage::sole();
        $this->assertSame($correct->id, $coverage->fee_price_id, 'coverage basis must resolve the SAME grade-correct price, never the competing grade\'s 9999');
    }

    public function test_a_yearly_adjustment_basis_lookup_picks_the_correct_enrollment_mode_never_a_competing_modes_price(): void
    {
        // Two enrollment modes, each with their own monthly BASIS tariff
        // tagged option_type='enrollment_mode' (dimensionalCandidates()'s
        // own convention for mode-scoped pricing) — the student's real
        // enrollment mode is 'full_time' (FinanceOperationsTestCase).
        $otherMode = EnrollmentMode::create(['code' => 'part_time', 'name_ru' => 'Заочная', 'is_active' => true]);

        $fee = Fee::create(['name_ru' => 'Обучение (год, конкурирующие формы)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'yearly']);

        // The yearly (charged) price — grade-scoped only, resolves
        // unambiguously regardless of enrollment mode.
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'yearly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '15000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $correctBasis = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'option_type' => 'enrollment_mode', 'option_value' => 'full_time', 'amount' => '1500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'option_type' => 'enrollment_mode', 'option_value' => 'part_time', 'amount' => '8888.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'yearly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'calendar', 'billing_period' => 'yearly',
        ], $this->accountant);

        $item = $invoice->items->sole();
        $this->assertSame($correctBasis->id, $item->metadata['adjustment_basis_fee_price_id']);
        $this->assertSame('1500.00', $item->metadata['adjustment_basis_unit_amount']);
    }

    public function test_a_basis_lookup_with_no_dimensionally_matching_candidate_blocks_issuance_never_picks_the_closest_one(): void
    {
        $otherGrade = Grade::forceCreate(['name' => 'Совсем другая параллель', 'stage_id' => $this->enrollment->grade->stage_id, 'level' => 97]);

        $fee = Fee::create(['name_ru' => 'Обучение (год, без совпадения)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'yearly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'yearly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '15000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        // The ONLY monthly basis candidate belongs to a DIFFERENT grade —
        // no dimensionally-matching basis exists for this student's own
        // grade at all.
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $otherGrade->id, 'amount' => '1200.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'yearly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'calendar', 'billing_period' => 'yearly',
        ], $this->accountant);
    }

    // ================================================================
    // Corrective pass #3 (P0 Blocker 2 — complete canonical tariff-
    // dimension validation, everywhere). Confirmed real gap:
    // resolvePrice()'s explicit-fee_price_id branch validated
    // ['grade_group', 'payment_period', 'size', 'item', 'option_type',
    // 'option_value'] — grade_id and enrollment_mode_id were simply
    // absent, so a client submitting a wrong grade's explicit
    // fee_price_id passed as long as grade_group happened to match or
    // was blank. Fixed via explicitPriceMatchesSelection(), the ONE
    // canonical matching implementation this branch now uses.
    // ================================================================

    private function issueWithExplicitPrice(\App\Models\Fee $fee, int $priceId, string $paymentType = 'one_time', ?string $billingPeriod = null, string $tariffPaymentPeriod = 'monthly'): \App\Models\Invoice
    {
        // payment_period here is the TARIFF's own dimension (which
        // FeePrice row this is), distinct from $billingPeriod (the
        // invoice's calendar collection cadence) — a monthly-denominated
        // explicit tariff can still be charged once (payment_type=
        // one_time) or across a calendar schedule; either way the
        // selection must declare which payment_period-dimensioned price
        // it means, exactly like every other dimension.
        $item = ['fee_id' => $fee->id, 'fee_price_id' => $priceId, 'grade_group' => null, 'payment_period' => $tariffPaymentPeriod, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null];
        $data = [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [$item], 'payment_type' => $paymentType,
        ];
        if ($billingPeriod !== null) {
            $data['billing_period'] = $billingPeriod;
        }

        return app(InvoiceIssuanceService::class)->issue($this->student, $data, $this->accountant);
    }

    public function test_explicit_fee_price_id_for_a_monthly_tariff_belonging_to_a_wrong_grade_is_rejected(): void
    {
        $otherGrade = Grade::forceCreate(['name' => 'Другая параллель (явный тариф)', 'stage_id' => $this->enrollment->grade->stage_id, 'level' => 96]);
        $fee = Fee::create(['name_ru' => 'Обучение (явный тариф, месяц)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $wrongGradePrice = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $otherGrade->id, 'amount' => '9999.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        $this->issueWithExplicitPrice($fee, $wrongGradePrice->id, 'one_time');
    }

    public function test_explicit_fee_price_id_for_the_correct_grade_is_accepted(): void
    {
        $otherGrade = Grade::forceCreate(['name' => 'Другая параллель (явный тариф ок)', 'stage_id' => $this->enrollment->grade->stage_id, 'level' => 95]);
        $fee = Fee::create(['name_ru' => 'Обучение (явный тариф, верная параллель)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        $correctPrice = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $otherGrade->id, 'amount' => '9999.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $invoice = $this->issueWithExplicitPrice($fee, $correctPrice->id, 'one_time');
        $this->assertSame('1000.00', $invoice->items->sole()->unit_price);
    }

    public function test_explicit_fee_price_id_for_a_quarterly_tariff_belonging_to_a_wrong_grade_is_rejected(): void
    {
        $otherGrade = Grade::forceCreate(['name' => 'Другая параллель (явный квартал)', 'stage_id' => $this->enrollment->grade->stage_id, 'level' => 94]);
        $fee = Fee::create(['name_ru' => 'Обучение (явный тариф, квартал)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'quarterly']);
        $wrongGradePrice = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'quarterly', 'grade_id' => $otherGrade->id, 'amount' => '2800.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        $this->issueWithExplicitPrice($fee, $wrongGradePrice->id, 'calendar', 'quarterly', 'quarterly');
    }

    public function test_explicit_fee_price_id_for_a_yearly_tariff_belonging_to_a_wrong_grade_is_rejected(): void
    {
        $otherGrade = Grade::forceCreate(['name' => 'Другая параллель (явный год)', 'stage_id' => $this->enrollment->grade->stage_id, 'level' => 93]);
        $fee = Fee::create(['name_ru' => 'Обучение (явный тариф, год)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'yearly']);
        $wrongGradePrice = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'yearly', 'grade_id' => $otherGrade->id, 'amount' => '15000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        $this->issueWithExplicitPrice($fee, $wrongGradePrice->id, 'calendar', 'yearly', 'yearly');
    }

    public function test_explicit_fee_price_id_for_a_wrong_enrollment_mode_is_rejected(): void
    {
        $otherMode = EnrollmentMode::create(['code' => 'evening', 'name_ru' => 'Вечерняя', 'is_active' => true]);
        $fee = Fee::create(['name_ru' => 'Обучение (явный тариф, форма)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        $wrongModePrice = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'option_type' => 'enrollment_mode', 'option_value' => 'evening', 'amount' => '777.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        // The student's own enrollment mode is 'full_time' — this price
        // is explicitly scoped to 'evening'.

        $this->expectException(ValidationException::class);
        $this->issueWithExplicitPrice($fee, $wrongModePrice->id, 'one_time');
    }

    public function test_explicit_fee_price_id_for_the_correct_enrollment_mode_is_accepted(): void
    {
        $fee = Fee::create(['name_ru' => 'Обучение (явный тариф, верная форма)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        $correctModePrice = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'option_type' => 'enrollment_mode', 'option_value' => 'full_time', 'amount' => '888.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $invoice = $this->issueWithExplicitPrice($fee, $correctModePrice->id, 'one_time');
        $this->assertSame('888.00', $invoice->items->sole()->unit_price);
    }

    public function test_explicit_fee_price_id_that_is_stale_past_its_end_date_is_rejected(): void
    {
        $fee = Fee::create(['name_ru' => 'Обучение (просроченный тариф)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        $stale = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2025-08-01', 'end_date' => '2025-12-31', 'is_active' => true]);
        // Another price exists for the same grade so this isn't the
        // sole-candidate prepayment exception.
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1100.00', 'currency' => 'EGP', 'start_date' => '2026-01-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        $this->issueWithExplicitPrice($fee, $stale->id, 'one_time');
    }

    public function test_explicit_fee_price_id_that_is_not_yet_effective_and_has_a_sibling_is_rejected(): void
    {
        $fee = Fee::create(['name_ru' => 'Обучение (будущий тариф)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-01-31', 'is_active' => true]);
        $future = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1200.00', 'currency' => 'EGP', 'start_date' => '2027-02-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        // Pricing date (2026-09-01) is before $future's own start_date,
        // and a sibling exists for the same dimensions — not usable via
        // the sole-candidate prepayment exception.

        $this->expectException(ValidationException::class);
        $this->issueWithExplicitPrice($fee, $future->id, 'one_time');
    }

    public function test_explicit_fee_price_id_with_wrong_academic_year_is_rejected(): void
    {
        $otherYear = \App\Models\AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => true]);
        $fee = Fee::create(['name_ru' => 'Обучение (чужой год)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        $wrongYearPrice = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $otherYear->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        $this->issueWithExplicitPrice($fee, $wrongYearPrice->id, 'one_time');
    }

    public function test_explicit_fee_price_id_with_wrong_currency_is_rejected(): void
    {
        // FeePrice itself hard-enforces EGP-only at the model layer
        // (FeePrice::booted()'s own saving guard — "Для цен используется
        // валюта EGP.") — a non-EGP row can never actually be created
        // through normal application code, confirmed by this test's own
        // first attempt (via the model) throwing that exact guard before
        // ever reaching the resolver. The resolver's OWN currency check
        // (InvoiceCalculationService::resolvePrice()) is therefore
        // defense-in-depth for a row that could only exist via a raw
        // insert bypassing Eloquent entirely — constructed here directly
        // to prove that defense-in-depth genuinely still works, not
        // merely assumed to.
        $fee = Fee::create(['name_ru' => 'Обучение (чужая валюта)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        $wrongCurrencyId = \Illuminate\Support\Facades\DB::table('fee_prices')->insertGetId([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly',
            'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'USD',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->issueWithExplicitPrice($fee, $wrongCurrencyId, 'one_time');
    }

    public function test_explicit_fee_price_id_that_is_inactive_is_rejected(): void
    {
        $fee = Fee::create(['name_ru' => 'Обучение (неактивный тариф)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        $inactive = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'grade_id' => $this->enrollment->grade_id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => false]);

        $this->expectException(ValidationException::class);
        $this->issueWithExplicitPrice($fee, $inactive->id, 'one_time');
    }
}
