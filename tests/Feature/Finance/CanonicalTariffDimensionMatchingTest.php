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
}
