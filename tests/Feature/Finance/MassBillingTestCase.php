<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\BillingBatch;
use App\Models\BillingBatchStudent;
use App\Models\EnrollmentMode;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class MassBillingTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $accountant;
    protected AcademicYear $year;
    protected Stage $stage;
    protected Grade $grade;
    protected EnrollmentMode $mode;
    protected SchoolClass $classA;
    protected SchoolClass $classB;
    protected Fee $tuition;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');

        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $this->grade = Grade::forceCreate(['name' => '1 КЛАСС', 'stage_id' => $this->stage->id, 'level' => 1]);
        $this->mode = EnrollmentMode::create(['code' => 'full_time', 'name_ru' => 'Очная', 'is_active' => true]);
        $this->classA = SchoolClass::create(['grade_id' => $this->grade->id, 'code' => '1-А', 'name_ru' => '1-А', 'name_ar' => '1-A', 'is_active' => true]);
        $this->classB = SchoolClass::create(['grade_id' => $this->grade->id, 'code' => '1-Б', 'name_ru' => '1-Б', 'name_ar' => '1-B', 'is_active' => true]);

        $this->tuition = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $this->tuition->id, 'academic_year_id' => $this->year->id, 'grade_id' => $this->grade->id, 'amount' => '1200.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
    }

    protected function makeStudent(string $suffix = ''): Student
    {
        return Student::create([
            'last_name_ru' => 'Иванов'.$suffix, 'first_name_ru' => 'Иван', 'phone' => '+2010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        ]);
    }

    protected function enroll(Student $student, ?SchoolClass $class = null, string $status = 'active', ?AcademicYear $year = null, ?Grade $grade = null): Enrollment
    {
        $class ??= $this->classA;
        $year ??= $this->year;
        $grade ??= $this->grade;

        return Enrollment::create([
            'student_id' => $student->id, 'academic_year_id' => $year->id, 'enrollment_mode_id' => $this->mode->id,
            'stage_id' => $this->stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id, 'academic_year' => $year->name,
            'enrollment_date' => $year->start_date->toDateString(), 'enrolled_at' => $year->start_date->toDateString(),
            'status' => $status, 'is_active' => $status === 'active',
        ]);
    }

    protected function enrolledStudent(?SchoolClass $class = null, string $status = 'active', ?AcademicYear $year = null, ?Grade $grade = null, string $suffix = ''): Student
    {
        $student = $this->makeStudent($suffix);
        $this->enroll($student, $class, $status, $year, $grade);

        return $student;
    }

    /**
     * @param  array<int, int>  $classIds
     * @param  array<int, int>  $include
     * @param  array<int, int>  $exclude
     */
    protected function makeBatch(string $mode = BillingBatch::TARGET_MODE_CLASSES, array $classIds = [], array $include = [], array $exclude = [], ?Fee $fee = null, string $issueDate = '2026-09-01', int $quantity = 1): BillingBatch
    {
        $batch = BillingBatch::create([
            'academic_year_id' => $this->year->id, 'fee_id' => ($fee ?? $this->tuition)->id, 'quantity' => $quantity,
            'issue_date' => $issueDate, 'due_date' => '2027-01-01', 'target_mode' => $mode, 'created_by' => $this->accountant->id,
        ]);

        foreach ($classIds as $classId) {
            $batch->classTargets()->create(['class_id' => $classId]);
        }
        foreach ($include as $studentId) {
            $batch->studentTargets()->create(['student_id' => $studentId, 'mode' => BillingBatchStudent::MODE_INCLUDE]);
        }
        foreach ($exclude as $studentId) {
            $batch->studentTargets()->create(['student_id' => $studentId, 'mode' => BillingBatchStudent::MODE_EXCLUDE]);
        }

        return $batch->fresh();
    }
}
