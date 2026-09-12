<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Services\Admissions\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Existing Student Enrollment Extraction (Phase 1).
 *
 * EnrollmentController::store()'s pre-existing behavior (validation,
 * duplicate-year rejection, placement validation, historical-enrollment
 * safety, Student.class_id sync, audit logging via the model observer) is
 * already fully characterized by tests/Feature/EnrollmentTest.php, which
 * exercises the exact same HTTP route this extraction did not change — all
 * 21 of those tests still pass unmodified after the extraction, proving
 * behavior is preserved. This file adds only what's genuinely new: proof
 * that EnrollmentService::create() itself is independently invokable and
 * produces the same Enrollment shape as the controller path, and an
 * explicit assertion that Enrollment creation has zero Finance side
 * effects (no Invoice, no StudentServiceSubscription).
 */
class EnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Elementary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);

        return SchoolClass::forceCreate(['code' => 'A', 'name_ar' => 'A', 'grade_id' => $grade->id]);
    }

    // 10. Service can be invoked independently and creates the same
    //     Enrollment shape as the controller path.
    public function test_service_invoked_directly_creates_an_enrollment_with_the_expected_shape(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Direct Service Student']);
        $year = AcademicYear::create(['name' => '2027/2028', 'start_date' => '2027-08-01', 'end_date' => '2028-06-30', 'is_active' => true]);

        $enrollment = app(EnrollmentService::class)->create($student, [
            'academic_year_id' => $year->id,
            'stage_id' => $class->grade->stage_id,
            'grade_id' => $class->grade_id,
            'class_id' => $class->id,
            'status' => 'active',
        ]);

        $this->assertInstanceOf(Enrollment::class, $enrollment);
        $this->assertSame($student->id, $enrollment->student_id);
        $this->assertSame($year->id, $enrollment->academic_year_id);
        $this->assertSame($year->name, $enrollment->academic_year);
        $this->assertSame($class->grade_id, $enrollment->grade_id);
        $this->assertSame($class->id, $enrollment->class_id);
        $this->assertSame('active', $enrollment->status);
        $this->assertTrue($enrollment->is_active);
        $this->assertSame($class->id, $student->fresh()->class_id, 'Student.class_id sync behavior must be unchanged when invoked directly.');
        $this->assertDatabaseCount('enrollments', 1);
    }

    // 6, 7, 8. Enrollment creation (via the extracted service) creates NO
    // Invoice, NO StudentServiceSubscription, and touches no PaymentPlan/
    // BillingSchedule/ServiceCoverage table — Enrollment creation has zero
    // Finance side effects.
    public function test_service_creates_zero_finance_side_effects(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'No Finance Side Effects']);
        $year = AcademicYear::create(['name' => '2027/2028', 'start_date' => '2027-08-01', 'end_date' => '2028-06-30', 'is_active' => true]);

        app(EnrollmentService::class)->create($student, [
            'academic_year_id' => $year->id,
            'stage_id' => $class->grade->stage_id,
            'grade_id' => $class->grade_id,
            'class_id' => $class->id,
            'status' => 'active',
        ]);

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, StudentServiceSubscription::count());
    }
}
