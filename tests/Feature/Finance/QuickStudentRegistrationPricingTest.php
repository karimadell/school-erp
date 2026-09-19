<?php

namespace Tests\Feature\Finance;

use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeeBillingPeriod;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\ServiceCoverage;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Services\Admissions\QuickStudentRegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuickStudentRegistrationPricingTest extends QuickRegistrationUxTestCase
{
    public function test_activity_is_excluded_from_every_quick_registration_boundary_without_partial_writes(): void
    {
        $structure = $this->structure();
        [$year, , $grade] = $structure;
        $activity = $this->fee('Экскурсия в аквариум', Fee::CATEGORY_ACTIVITY);

        foreach ([
            Fee::CATEGORY_REGISTRATION => 'Регистрационный взнос',
            Fee::CATEGORY_TUITION => 'Единое обучение',
            Fee::CATEGORY_TRANSPORT => 'Транспорт',
            Fee::CATEGORY_FOOD => 'Питание',
            Fee::CATEGORY_UNIFORM => 'Школьная форма',
            Fee::CATEGORY_BOOKS => 'Учебники',
            Fee::CATEGORY_EXTRA_CLASSES => 'Дополнительные занятия',
            Fee::CATEGORY_OTHER => 'Прочая услуга',
        ] as $category => $name) {
            $this->fee($name, $category);
        }

        $page = $this->actingAs($this->accountant)
            ->get(route('dashboard.quick-registration.create'))
            ->assertOk()
            ->assertDontSee('Экскурсия в аквариум');

        foreach (['Регистрационный взнос', 'Единое обучение', 'Транспорт', 'Питание', 'Школьная форма', 'Учебники', 'Дополнительные занятия', 'Прочая услуга'] as $name) {
            $page->assertSee($name);
        }

        $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $activity->id, 'quantity' => 1, 'academic_year_id' => $year->id,
            'grade_id' => $grade->id, 'enrollment_mode_id' => $structure[4]->id,
            'registration_date' => '2026-09-10',
        ])->assertUnprocessable()->assertJsonValidationErrors('fee_id');

        $payload = $this->payload($structure, $activity);
        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $payload)
            ->assertSessionHasErrors('services');

        try {
            app(QuickStudentRegistrationService::class)->register($payload, $this->accountant);
            $this->fail('Direct registration service accepted an activity Fee.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('services', $exception->errors());
        }

        $this->assertSame(0, Student::count());
        $this->assertSame(0, Enrollment::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoiceItem::count());
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, ServiceCoverage::count());
        $this->assertSame(0, StudentServiceSubscription::count());
        $this->assertSame(0, DB::table('cash_transactions')->count());
    }

    public function test_registration_offers_exactly_four_canonical_modes_and_only_unified_tuition(): void
    {
        [$year, , $grade] = $this->structure();
        $legacyMode = EnrollmentMode::create(['code' => 'legacy_other', 'name_ru' => 'Устаревший режим', 'is_active' => true]);
        $tuition = Fee::create(['name_ru' => 'Единое обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => 0, 'is_active' => true]);
        Fee::create(['name_ru' => 'Старое очное', 'category' => Fee::CATEGORY_TUITION_REGULAR, 'amount' => 0, 'is_active' => true]);
        Fee::create(['name_ru' => 'Старое семейное', 'category' => Fee::CATEGORY_TUITION_FAMILY, 'amount' => 0, 'is_active' => true]);
        Fee::create(['name_ru' => 'Старый экстернат', 'category' => Fee::CATEGORY_TUITION_EXTERNAL, 'amount' => 0, 'is_active' => true]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk();

        foreach (['Очная форма', 'Семейная форма', 'Экстернат', 'Без зачисления', 'Единое обучение'] as $label) {
            $response->assertSee($label);
        }
        foreach (['Устаревший режим', 'Старое очное', 'Старое семейное', 'Старый экстернат'] as $label) {
            $response->assertDontSee($label);
        }

        $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $tuition->id, 'quantity' => 1, 'academic_year_id' => $year->id, 'grade_id' => $grade->id,
            'enrollment_mode_id' => $legacyMode->id, 'payment_period' => 'monthly', 'registration_date' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('enrollment_mode_id');
    }

    public function test_incomplete_canonical_mode_catalog_fails_clearly(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        EnrollmentMode::where('code', EnrollmentMode::FAMILY)->delete();

        $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()
            ->assertSee('Формы обучения не настроены.')
            ->assertSee(EnrollmentMode::FAMILY)
            ->assertSee('disabled', false);

        $payload = $this->payload($structure, $fee);
        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $payload)
            ->assertSessionHasErrors('enrollment_mode_id');

        try {
            app(QuickStudentRegistrationService::class)->register($payload, $this->accountant);
            $this->fail('Direct registration service accepted an incomplete canonical mode catalog.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('enrollment_mode_id', $exception->errors());
        }

        $this->assertSame(0, Student::count());
        $this->assertSame(0, Enrollment::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoiceItem::count());
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, ServiceCoverage::count());
        $this->assertSame(0, StudentServiceSubscription::count());
    }

    public function test_all_canonical_modes_preview_and_submit_against_the_unified_tuition_matrix(): void
    {
        $structure = $this->structure();
        [$year, , $grade] = $structure;
        $year->update(['start_date' => '2026-09-01']);
        $grade->forceFill(['name' => '4 КЛАСС', 'level' => 4])->save();
        $fee = $this->tuition();
        FeeBillingPeriod::create(['fee_id' => $fee->id, 'billing_period' => 'monthly']);
        FeeBillingPeriod::create(['fee_id' => $fee->id, 'billing_period' => 'yearly']);

        foreach ([
            EnrollmentMode::FULL_TIME => ['monthly' => '5500.00', 'yearly' => '49500.00'],
            EnrollmentMode::FAMILY => ['monthly' => '5500.00', 'yearly' => '49500.00'],
            EnrollmentMode::NO_ENROLLMENT => ['monthly' => '5500.00', 'yearly' => '49500.00'],
            EnrollmentMode::EXTERNAL => ['monthly' => '3200.00', 'yearly' => '25600.00'],
        ] as $code => $periods) {
            foreach ($periods as $period => $amount) {
                $this->price($fee, $year->id, '1–4 классы', $amount, $code, $period);
            }
        }

        foreach ([EnrollmentMode::FULL_TIME => '5500.00', EnrollmentMode::FAMILY => '5500.00', EnrollmentMode::NO_ENROLLMENT => '5500.00', EnrollmentMode::EXTERNAL => '3200.00'] as $code => $amount) {
            $mode = EnrollmentMode::where('code', $code)->sole();
            $preview = ['fee_id' => $fee->id, 'quantity' => 1, 'academic_year_id' => $year->id,
                'grade_id' => $grade->id, 'payment_period' => 'monthly', 'registration_date' => '2026-09-10',
                'enrollment_mode_id' => $mode->id];
            $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), $preview)
                ->assertOk()->assertJsonPath('unit_price', $amount);

            $payload = $this->payload($structure, $fee, [
                'student_last_name_ru' => 'Ученик '.$code,
                'phone' => '+201'.str_pad((string) $mode->id, 9, '0', STR_PAD_LEFT),
                'registration_date' => '2026-09-10',
                'enrollment_mode_id' => $mode->id,
                'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'payment_period' => 'monthly', 'paid_now' => '0.00']],
            ]);
            $this->post(route('dashboard.quick-registration.store'), $payload)->assertRedirect(route('dashboard.quick-registration.create'));
            $this->assertSame($amount, (string) Invoice::latest('id')->firstOrFail()->total_amount);
        }

        $external = EnrollmentMode::where('code', EnrollmentMode::EXTERNAL)->sole();
        $this->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $fee->id, 'quantity' => 1, 'academic_year_id' => $year->id, 'grade_id' => $grade->id,
            'enrollment_mode_id' => $external->id, 'payment_period' => 'yearly', 'registration_date' => '2026-09-10',
        ])->assertOk()->assertJsonPath('unit_price', '25600.00');
    }

    public function test_legacy_tuition_requests_and_missing_external_scope_fail_without_partial_writes(): void
    {
        $structure = $this->structure();
        [$year, $stage, $grade, $class] = $structure;
        $year->update(['start_date' => '2026-09-01']);
        $grade->forceFill(['name' => '5 КЛАСС', 'level' => 5])->save();
        $fee = $this->tuition();
        FeeBillingPeriod::create(['fee_id' => $fee->id, 'billing_period' => 'monthly']);
        $legacy = Fee::create(['name_ru' => 'Старый экстернат', 'category' => Fee::CATEGORY_TUITION_EXTERNAL, 'amount' => 0, 'is_active' => true]);
        foreach ([EnrollmentMode::FULL_TIME, EnrollmentMode::FAMILY, EnrollmentMode::NO_ENROLLMENT] as $code) {
            $this->price($fee, $year->id, '5–6 классы', '6500.00', $code, 'monthly');
        }
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'grade_group' => '5–6 классы',
            'payment_period' => 'monthly', 'amount' => '9999.00', 'currency' => 'EGP',
            'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        $external = EnrollmentMode::where('code', EnrollmentMode::EXTERNAL)->sole();

        $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $fee->id, 'quantity' => 1, 'academic_year_id' => $year->id, 'grade_id' => $grade->id,
            'enrollment_mode_id' => $external->id, 'payment_period' => 'monthly', 'registration_date' => '2026-09-10',
        ])->assertUnprocessable()->assertJsonValidationErrors('fees');

        $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $legacy->id, 'quantity' => 1, 'academic_year_id' => $year->id, 'grade_id' => $grade->id,
            'enrollment_mode_id' => $external->id, 'payment_period' => 'monthly', 'registration_date' => '2026-09-10',
        ])->assertUnprocessable();

        $payload = [
            'student_last_name_ru' => 'Проверка', 'student_first_name_ru' => 'Откат', 'phone' => '+201009999999',
            'registration_date' => '2026-09-10', 'academic_year_id' => $year->id, 'stage_id' => $stage->id,
            'grade_id' => $grade->id, 'class_id' => $class->id, 'enrollment_mode_id' => $external->id,
            'services' => [['fee_id' => $legacy->id, 'quantity' => 1, 'payment_period' => 'monthly', 'paid_now' => '0.00']],
        ];
        $this->post(route('dashboard.quick-registration.store'), $payload)->assertSessionHasErrors('services');

        $payload['services'][0]['fee_id'] = $fee->id;
        $this->post(route('dashboard.quick-registration.store'), $payload)->assertSessionHasErrors();

        $this->assertSame(0, Student::count());
        $this->assertSame(0, Enrollment::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoiceItem::count());
        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, ServiceCoverage::count());
    }

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
            'registration_date' => '2026-09-10',
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
        $external = EnrollmentMode::where('code', EnrollmentMode::EXTERNAL)->sole();
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

    private function price(Fee $fee, int $yearId, string $group, string $amount, ?string $modeCode = null, string $period = 'yearly'): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $fee->id,
            'academic_year_id' => $yearId,
            'grade_group' => $group,
            'payment_period' => $period,
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
