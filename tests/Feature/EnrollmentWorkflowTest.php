<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\InvoiceItem;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use App\Services\Admissions\SchoolEnrollmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class EnrollmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_modern_russian_enrollment_wizard_renders_active_structure_and_fee_prices(): void
    {
        [$year, $stage, $grade, $class, $prices] = $this->catalog();

        $this->actingAs($this->admin)->get(route('dashboard.school-enrollment.create'))
            ->assertOk()
            ->assertSee('Шаг 1 — Ученик')
            ->assertSee('Шаг 2 — Родители')
            ->assertSee('Шаг 3 — Учебные данные')
            ->assertSee('Шаг 4 — Услуги')
            ->assertSee('Шаг 5 — Проверка')
            ->assertSee($year->name)
            ->assertSee($stage->name)
            ->assertSee('1 500.00 EGP')
            ->assertSee('Завершить зачисление')
            ->assertSee('data-enrollment-wizard', false);
    }

    public function test_enrollment_creates_student_subscriptions_and_unpaid_invoice_atomically(): void
    {
        $catalog = $this->catalog();
        $response = $this->actingAs($this->admin)
            ->post(route('dashboard.school-enrollment.store'), $this->payload($catalog));

        $invoice = \App\Models\Invoice::firstOrFail();
        $response->assertRedirect(route('dashboard.invoices.show', $invoice))
            ->assertSessionHas('success', 'Ученик успешно зачислен. Черновик счёта создан без оплаты.');

        $student = \App\Models\Student::firstOrFail();
        $this->assertSame('Иванов Иван Иванович', $student->name);
        $this->assertSame('Ivan Ivanov', $student->documents['name_en']);
        $this->assertSame('إيفان إيفانوف', $student->documents['name_ar']);
        $this->assertSame('Иванов Сергей', $student->documents['father']['name']);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('student_service_subscriptions', 2);
        $this->assertDatabaseCount('invoice_items', 2);
        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertSame('unpaid', $invoice->status);
        $this->assertSame('0.00', $invoice->paid_amount);
        $this->assertSame('3500.00', $invoice->total_amount);
        $this->assertSame('3500.00', $invoice->remaining_amount);
        $this->assertSame('EGP', $invoice->currency);
    }

    public function test_validation_prevents_orphan_records(): void
    {
        $catalog = $this->catalog();
        $payload = $this->payload($catalog, ['student_name_ru' => '', 'fee_price_ids' => []]);

        $this->actingAs($this->admin)->post(route('dashboard.school-enrollment.store'), $payload)
            ->assertSessionHasErrors(['student_name_ru', 'fee_price_ids']);

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_failure_during_invoice_items_rolls_back_every_database_record(): void
    {
        $catalog = $this->catalog();
        InvoiceItem::creating(function (): void {
            throw new RuntimeException('Проверка отката');
        });

        try {
            app(SchoolEnrollmentService::class)->enroll($this->payload($catalog), $this->admin);
            $this->fail('Expected enrollment failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Проверка отката', $exception->getMessage());
        }

        foreach (['students', 'enrollments', 'student_service_subscriptions', 'invoices', 'invoice_items'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_manage_students_permission_is_required(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('accountant');

        $this->actingAs($user)->get(route('dashboard.school-enrollment.index'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.school-enrollment.create'))->assertForbidden();
        $this->actingAs($user)->post(route('dashboard.school-enrollment.store'), [])->assertForbidden();
    }

    /** @return array{AcademicYear, Stage, Grade, SchoolClass, array<int, FeePrice>} */
    private function catalog(): array
    {
        $year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $stage->id, 'level' => 1]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'А', 'name_ru' => '1-А', 'name_ar' => 'A', 'is_active' => true]);
        EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очная форма', 'is_active' => true]);

        $registration = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1.00', 'is_active' => true]);
        $tuition = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $prices = [
            FeePrice::create(['fee_id' => $registration->id, 'academic_year_id' => $year->id, 'amount' => '1500.00', 'currency' => 'EGP', 'start_date' => $year->start_date, 'end_date' => $year->end_date, 'payment_period' => 'yearly', 'is_active' => true]),
            FeePrice::create(['fee_id' => $tuition->id, 'academic_year_id' => $year->id, 'amount' => '2000.00', 'currency' => 'EGP', 'start_date' => $year->start_date, 'end_date' => $year->end_date, 'grade_id' => $grade->id, 'payment_period' => 'monthly', 'is_active' => true]),
        ];

        return [$year, $stage, $grade, $class, $prices];
    }

    private function payload(array $catalog, array $overrides = []): array
    {
        [$year, $stage, $grade, $class, $prices] = $catalog;

        return array_replace([
            'student_name_ru' => 'Иванов Иван Иванович',
            'student_name_en' => 'Ivan Ivanov',
            'student_name_ar' => 'إيفان إيفانوف',
            'gender' => 'male',
            'birth_date' => '2018-03-10',
            'nationality' => 'Россия',
            'identity_document' => 'Свидетельство 12345',
            'father_name' => 'Иванов Сергей',
            'father_phone' => '+20 100 000 0000',
            'father_email' => 'father@example.test',
            'mother_name' => 'Иванова Анна',
            'emergency_contact' => '+20 111 111 1111',
            'academic_year_id' => $year->id,
            'stage_id' => $stage->id,
            'grade_id' => $grade->id,
            'class_id' => $class->id,
            'fee_price_ids' => collect($prices)->pluck('id')->all(),
        ], $overrides);
    }
}
