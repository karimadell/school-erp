<?php

namespace Tests\Feature\Finance;

use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;

class QuickStudentRegistrationPricingTest extends QuickRegistrationUxTestCase
{
    public function test_grade_four_full_time_resolves_canonical_academic_year_price(): void
    {
        [$year, $stage, $grade, , $mode] = $this->structure();
        $year->update(['start_date' => '2026-09-01']);
        $grade->forceFill(['name' => '4 КЛАСС', 'level' => 4])->save();
        $mode->update(['code' => EnrollmentMode::FULL_TIME, 'name_ru' => 'Очная форма обучения']);
        $fee = $this->tuition();
        $this->price($fee, $year->id, '1–4 классы', '40500.00');

        $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $fee->id,
            'quantity' => 1,
            'academic_year_id' => $year->id,
            'grade_id' => $grade->id,
            'enrollment_mode_id' => $mode->id,
            'payment_period' => 'yearly',
            'registration_date' => '2026-08-04',
        ])->assertOk()->assertJson(['unit_price' => '40500.00', 'amount' => '40500.00', 'currency' => 'EGP']);

        $this->get(route('dashboard.quick-registration.create'))
            ->assertOk()
            ->assertSee('id="enrollment-mode"', false)
            ->assertSee('[academicYear, schoolClass, enrollmentMode, registrationDate]', false);
    }

    public function test_changing_enrollment_mode_changes_canonical_price(): void
    {
        [$year, , $grade, , $fullTime] = $this->structure();
        $grade->forceFill(['name' => '4 КЛАСС', 'level' => 4])->save();
        $fullTime->update(['code' => EnrollmentMode::FULL_TIME, 'name_ru' => 'Очная форма обучения']);
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => true]);
        $fee = $this->tuition();
        $this->price($fee, $year->id, '1–4 классы', '40500.00', EnrollmentMode::FULL_TIME);
        $this->price($fee, $year->id, '1–4 классы', '25600.00', 'external');

        $payload = ['fee_id' => $fee->id, 'quantity' => 1, 'academic_year_id' => $year->id,
            'grade_id' => $grade->id, 'payment_period' => 'yearly', 'registration_date' => '2026-09-10'];

        $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), $payload + ['enrollment_mode_id' => $fullTime->id])
            ->assertOk()->assertJsonPath('unit_price', '40500.00');
        $this->postJson(route('dashboard.quick-registration.price'), $payload + ['enrollment_mode_id' => $external->id])
            ->assertOk()->assertJsonPath('unit_price', '25600.00');
    }

    public function test_changing_grade_changes_canonical_price(): void
    {
        [$year, $stage, $gradeFour, , $mode] = $this->structure();
        $gradeFour->forceFill(['name' => '4 КЛАСС', 'level' => 4])->save();
        $gradeFive = Grade::forceCreate(['name' => '5 КЛАСС', 'stage_id' => $stage->id, 'level' => 5]);
        $fee = $this->tuition();
        $this->price($fee, $year->id, '1–4 классы', '40500.00');
        $this->price($fee, $year->id, '5–6 классы', '49500.00');
        $payload = ['fee_id' => $fee->id, 'quantity' => 1, 'academic_year_id' => $year->id,
            'enrollment_mode_id' => $mode->id, 'payment_period' => 'yearly', 'registration_date' => '2026-09-10'];

        $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), $payload + ['grade_id' => $gradeFour->id])
            ->assertOk()->assertJsonPath('unit_price', '40500.00');
        $this->postJson(route('dashboard.quick-registration.price'), $payload + ['grade_id' => $gradeFive->id])
            ->assertOk()->assertJsonPath('unit_price', '49500.00');
    }

    private function tuition(): Fee
    {
        return Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => true]);
    }

    private function price(Fee $fee, int $yearId, string $group, string $amount, ?string $modeCode = null): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $fee->id,
            'academic_year_id' => $yearId,
            'grade_group' => $group,
            'payment_period' => 'yearly',
            'option_type' => $modeCode ? 'enrollment_mode' : null,
            'option_value' => $modeCode,
            'amount' => $amount,
            'currency' => 'EGP',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);
    }
}
