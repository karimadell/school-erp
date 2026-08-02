<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickStudentRegistrationValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private AcademicYear $year;
    private Stage $stage;
    private Grade $grade;
    private SchoolClass $class;
    private EnrollmentMode $mode;
    private Fee $fee;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('accountant');
        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->stage = Stage::create(['name' => 'Начальная школа', 'is_active' => true]);
        $this->grade = Grade::create(['name' => '1 класс', 'stage_id' => $this->stage->id]);
        $this->class = SchoolClass::create(['grade_id' => $this->grade->id, 'code' => '1-А', 'name_ar' => '1-A', 'name_ru' => '1-А', 'is_active' => true]);
        $this->mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очное обучение', 'is_active' => true]);
        $this->fee = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1000.00', 'is_active' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'student_last_name_ru' => 'Иванов', 'student_first_name_ru' => 'Иван',
            'student_patronymic_ru' => null, 'phone' => '+20 101 234 5678',
            'academic_year_id' => $this->year->id, 'stage_id' => $this->stage->id,
            'grade_id' => $this->grade->id, 'class_id' => $this->class->id,
            'enrollment_mode_id' => $this->mode->id, 'registration_date' => '2026-08-02',
            'services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ], $overrides);
    }

    public function test_russian_validation_rejects_bad_phone_and_missing_class(): void
    {
        $response = $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $this->payload([
            'phone' => 'не телефон', 'class_id' => null,
        ]));

        $response->assertSessionHasErrors(['phone', 'class_id']);
        $this->assertSame('Укажите корректный номер телефона.', session('errors')->first('phone'));
    }

    public function test_class_must_belong_to_selected_grade(): void
    {
        $otherGrade = Grade::create(['name' => '2 класс', 'stage_id' => $this->stage->id]);
        $otherClass = SchoolClass::create(['grade_id' => $otherGrade->id, 'code' => '2-А', 'name_ar' => '2-A', 'is_active' => true]);

        $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $this->payload([
            'class_id' => $otherClass->id,
        ]))->assertSessionHasErrors('class_id');
    }

    public function test_negative_and_overpayment_are_rejected_without_silent_capping(): void
    {
        $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'paid_now' => '-1.00']],
        ]))->assertSessionHasErrors('services.0.paid_now');

        $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'paid_now' => '1000.01']],
            'cash_account_id' => \App\Models\CashAccount::create(['name' => 'Касса', 'type' => 'cash'])->id,
            'payment_method' => 'cash',
        ]))->assertSessionHasErrors('services.0.paid_now');
        $this->assertDatabaseCount('students', 0);
    }

    public function test_positive_payment_requires_method_and_cash_account(): void
    {
        $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'paid_now' => '1.00']],
        ]))->assertSessionHasErrors(['payment_method', 'cash_account_id']);
    }

    public function test_browser_financial_fields_are_prohibited(): void
    {
        $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $this->payload([
            'subtotal_amount' => '0.01',
            'services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'paid_now' => '0.00', 'unit_price' => '0.01']],
        ]))->assertSessionHasErrors(['subtotal_amount', 'services.0.unit_price']);
    }
}
