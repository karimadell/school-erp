<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceAcademicYearConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Student $student;
    private AcademicYear $year;
    private Fee $fee;
    private CashAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('accountant');
        $this->student = Student::create(['name' => 'Ученик']);
        $this->year = AcademicYear::create([
            'name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        $this->fee = Fee::create(['name_ru' => 'Обучение', 'amount' => 100, 'is_active' => true]);
        $this->account = CashAccount::create(['name' => 'Касса', 'type' => 'cash']);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'due_date' => '2026-09-01',
            'fees' => [$this->fee->id],
            'cash_account_id' => $this->account->id,
        ], $overrides);
    }

    private function enroll(): void
    {
        $stage = Stage::create(['name' => 'Школа']);
        $grade = Grade::create(['name' => 'Класс', 'stage_id' => $stage->id]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A', 'name_ar' => 'A']);
        Enrollment::create([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id,
            'status' => 'active', 'is_active' => true,
        ]);
    }

    public function test_missing_academic_year_is_rejected(): void
    {
        $this->actingAs($this->user)->post(route('dashboard.invoices.store'), $this->payload(['academic_year_id' => null]))
            ->assertSessionHasErrors('academic_year_id');
    }

    public function test_inactive_year_is_rejected(): void
    {
        $this->enroll();
        $this->year->update(['is_active' => false]);

        $this->actingAs($this->user)->post(route('dashboard.invoices.store'), $this->payload())
            ->assertSessionHasErrors('academic_year_id');
    }

    public function test_student_without_matching_active_enrollment_is_rejected(): void
    {
        $response = $this->actingAs($this->user)->post(route('dashboard.invoices.store'), $this->payload());
        $response->assertSessionHasErrors('student_id');
        $this->assertStringContainsString('не имеет активного зачисления', session('errors')->first('student_id'));
    }

    public function test_matching_active_year_enrollment_succeeds(): void
    {
        $this->enroll();
        $this->actingAs($this->user)->post(route('dashboard.invoices.store'), $this->payload())
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_due_date_outside_academic_year_is_rejected(): void
    {
        $this->enroll();
        $this->actingAs($this->user)->post(route('dashboard.invoices.store'), $this->payload(['due_date' => '2027-07-01']))
            ->assertSessionHasErrors('due_date');
    }
}
