<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Elementary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);

        return SchoolClass::forceCreate(['code' => 'A', 'name_ar' => 'A', 'grade_id' => $grade->id]);
    }

    protected function enroll(Student $student, AcademicYear $year, SchoolClass $class, string $status = 'active')
    {
        $user = User::factory()->create();

        return $this->actingAs($user)->post(route('dashboard.enrollments.store', $student), [
            'academic_year_id' => $year->id,
            'stage_id' => $class->grade_id ? $class->grade->stage_id : Stage::first()->id,
            'grade_id' => $class->grade_id,
            'class_id' => $class->id,
            'status' => $status,
        ]);
    }

    /**
     * Batch 3 (B5): academic_year_id was nullable as a Sprint 0 stopgap;
     * this documented that gap by asserting enrollment-without-a-year
     * succeeded. academic_year_id is now required, so the same scenario
     * must fail validation instead — this replaces that expectation rather
     * than merely extending it.
     */
    public function test_enrolling_without_an_academic_year_now_fails_validation(): void
    {
        $user = User::factory()->create();
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);

        $response = $this->actingAs($user)
            ->post(route('dashboard.enrollments.store', $student), [
                'stage_id' => $class->grade->stage_id,
                'grade_id' => $class->grade_id,
                'class_id' => $class->id,
                'status' => 'active',
            ]);

        $response->assertSessionHasErrors('academic_year_id');
        $this->assertDatabaseCount('enrollments', 0);
    }

    /**
     * Core Batch-3 fix: creating a new-year enrollment must not deactivate
     * the previous year's enrollment. is_active describes only a record's
     * own year, never a cross-year "current enrollment" flag.
     */
    public function test_creating_a_new_year_enrollment_does_not_deactivate_the_previous_year_enrollment(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);

        $lastYear = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);
        $thisYear = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $this->enroll($student, $lastYear, $class)->assertSessionDoesntHaveErrors();
        $previousEnrollment = Enrollment::where('academic_year_id', $lastYear->id)->firstOrFail();
        $this->assertTrue($previousEnrollment->is_active);

        $this->enroll($student, $thisYear, $class)->assertSessionDoesntHaveErrors();

        $this->assertTrue(
            $previousEnrollment->fresh()->is_active,
            'The previous year\'s enrollment must remain active — it is a historical statement, not a cross-year flag.'
        );
    }

    public function test_both_historical_enrollments_remain_valid_for_their_respective_years(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);

        $lastYear = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);
        $thisYear = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $this->enroll($student, $lastYear, $class);
        $this->enroll($student, $thisYear, $class);

        $this->assertDatabaseHas('enrollments', [
            'student_id' => $student->id, 'academic_year_id' => $lastYear->id, 'is_active' => 1,
        ]);
        $this->assertDatabaseHas('enrollments', [
            'student_id' => $student->id, 'academic_year_id' => $thisYear->id, 'is_active' => 1,
        ]);
        $this->assertDatabaseCount('enrollments', 2);
    }

    public function test_current_placement_is_resolved_through_the_active_academic_year(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);

        $lastYear = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);
        $thisYear = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $this->enroll($student, $lastYear, $class);
        $this->enroll($student, $thisYear, $class);

        $current = $student->fresh()->currentEnrollment;

        $this->assertNotNull($current);
        $this->assertSame($thisYear->id, $current->academic_year_id);
    }

    public function test_duplicate_enrollment_in_the_same_academic_year_remains_rejected(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'stage_id' => $class->grade->stage_id,
            'grade_id' => $class->grade_id,
            'class_id' => $class->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'stage_id' => $class->grade->stage_id,
            'grade_id' => $class->grade_id,
            'class_id' => $class->id,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    public function test_existing_enrollment_history_is_not_corrupted_by_a_later_enrollment(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);

        $yearOne = AcademicYear::create([
            'name' => '2024 / 2025', 'start_date' => '2024-09-01', 'end_date' => '2025-05-31', 'is_active' => false,
        ]);
        $yearTwo = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);
        $yearThree = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $this->enroll($student, $yearOne, $class);
        $enrollmentOne = Enrollment::where('academic_year_id', $yearOne->id)->firstOrFail();

        $this->enroll($student, $yearTwo, $class);
        $enrollmentTwo = Enrollment::where('academic_year_id', $yearTwo->id)->firstOrFail();

        $this->enroll($student, $yearThree, $class);

        // Neither historical record was touched by the later enrollments.
        $this->assertTrue($enrollmentOne->fresh()->is_active);
        $this->assertSame('active', $enrollmentOne->fresh()->status);
        $this->assertTrue($enrollmentTwo->fresh()->is_active);
        $this->assertSame('active', $enrollmentTwo->fresh()->status);
        $this->assertDatabaseCount('enrollments', 3);
    }
}
