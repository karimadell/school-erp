<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use App\Support\AcademicYearLock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
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

    /**
     * Batch 6: EnrollmentController::store()/update() now require
     * 'create enrollments'/'update enrollments'. This fixture grants
     * everything so tests focused on business logic (not authorization
     * itself) aren't blocked by the permission layer.
     */
    protected function authorizedUser(): User
    {
        $user = User::factory()->create();
        foreach (['view enrollments', 'create enrollments', 'update enrollments', 'delete enrollments'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['view enrollments', 'create enrollments', 'update enrollments', 'delete enrollments']);

        return $user;
    }

    protected function enroll(Student $student, AcademicYear $year, SchoolClass $class, string $status = 'active', ?int $enrollmentModeId = null)
    {
        $user = $this->authorizedUser();

        return $this->actingAs($user)->post(route('dashboard.enrollments.store', $student), array_filter([
            'academic_year_id' => $year->id,
            'enrollment_mode_id' => $enrollmentModeId,
            'stage_id' => $class->grade_id ? $class->grade->stage_id : Stage::first()->id,
            'grade_id' => $class->grade_id,
            'class_id' => $class->id,
            'status' => $status,
        ], fn ($value) => $value !== null));
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
        $user = $this->authorizedUser();
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

        AcademicYearLock::withoutLock(fn () => $this->enroll($student, $lastYear, $class))->assertSessionDoesntHaveErrors();
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

        AcademicYearLock::withoutLock(fn () => $this->enroll($student, $lastYear, $class));
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

        AcademicYearLock::withoutLock(fn () => $this->enroll($student, $lastYear, $class));
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

        AcademicYearLock::withoutLock(fn () => $this->enroll($student, $yearOne, $class));
        $enrollmentOne = Enrollment::where('academic_year_id', $yearOne->id)->firstOrFail();

        AcademicYearLock::withoutLock(fn () => $this->enroll($student, $yearTwo, $class));
        $enrollmentTwo = Enrollment::where('academic_year_id', $yearTwo->id)->firstOrFail();

        $this->enroll($student, $yearThree, $class);

        // Neither historical record was touched by the later enrollments.
        $this->assertTrue($enrollmentOne->fresh()->is_active);
        $this->assertSame('active', $enrollmentOne->fresh()->status);
        $this->assertTrue($enrollmentTwo->fresh()->is_active);
        $this->assertSame('active', $enrollmentTwo->fresh()->status);
        $this->assertDatabaseCount('enrollments', 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Batch 10: friendly validation in front of the pre-existing DB-level
    | unique(student_id, academic_year_id) constraint. That constraint
    | already made a duplicate impossible (see the raw-model test above,
    | which still exercises it directly) — these tests cover the request
    | layer, which previously let a duplicate submission crash with an
    | uncaught QueryException instead of a normal validation error.
    |--------------------------------------------------------------------------
    */

    public function test_duplicate_enrollment_via_store_is_rejected_with_a_friendly_validation_error(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $this->enroll($student, $year, $class)->assertSessionDoesntHaveErrors();

        $response = $this->enroll($student, $year, $class);

        $response->assertSessionHasErrors('academic_year_id');
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_updating_an_enrollment_to_collide_with_another_years_enrollment_is_rejected(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);
        $yearOne = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);
        $yearTwo = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        AcademicYearLock::withoutLock(fn () => $this->enroll($student, $yearOne, $class));
        $this->enroll($student, $yearTwo, $class);
        $enrollmentTwo = Enrollment::where('academic_year_id', $yearTwo->id)->firstOrFail();

        $user = $this->authorizedUser();
        $response = $this->actingAs($user)->put(route('dashboard.enrollments.update', $enrollmentTwo->id), [
            'academic_year_id' => $yearOne->id,
            'stage_id' => $class->grade->stage_id,
            'grade_id' => $class->grade_id,
            'class_id' => $class->id,
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('academic_year_id');
        $this->assertSame($yearTwo->id, $enrollmentTwo->fresh()->academic_year_id);
    }

    public function test_updating_an_enrollment_without_changing_its_year_is_not_rejected_as_a_duplicate(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $this->enroll($student, $year, $class);
        $enrollment = Enrollment::where('academic_year_id', $year->id)->firstOrFail();

        $user = $this->authorizedUser();
        $response = $this->actingAs($user)->put(route('dashboard.enrollments.update', $enrollment->id), [
            'academic_year_id' => $year->id,
            'stage_id' => $class->grade->stage_id,
            'grade_id' => $class->grade_id,
            'class_id' => $class->id,
            'status' => 'transferred',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame('transferred', $enrollment->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Batch 4: enrollment modes (regular, distance_learning)
    |--------------------------------------------------------------------------
    */

    public function test_a_regular_enrollment_persists_its_mode(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $regular = EnrollmentMode::create(['code' => EnrollmentMode::REGULAR, 'name_ru' => 'Очное обучение']);

        $this->enroll($student, $year, $class, 'active', $regular->id)->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('enrollments', [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'enrollment_mode_id' => $regular->id,
        ]);
    }

    public function test_a_distance_learning_enrollment_persists_its_mode(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $distance = EnrollmentMode::create(['code' => EnrollmentMode::DISTANCE_LEARNING, 'name_ru' => 'Дистанционное обучение']);

        $this->enroll($student, $year, $class, 'active', $distance->id)->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('enrollments', [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'enrollment_mode_id' => $distance->id,
        ]);
    }

    public function test_enrollment_mode_is_optional(): void
    {
        // Additive: existing callers that never set a mode are unaffected.
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $this->enroll($student, $year, $class)->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('enrollments', [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'enrollment_mode_id' => null,
        ]);
    }

    public function test_enrollment_mode_from_a_past_year_is_unaffected_by_a_new_years_different_mode(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);
        $lastYear = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);
        $thisYear = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $regular = EnrollmentMode::create(['code' => EnrollmentMode::REGULAR, 'name_ru' => 'Очное обучение']);
        $distance = EnrollmentMode::create(['code' => EnrollmentMode::DISTANCE_LEARNING, 'name_ru' => 'Дистанционное обучение']);

        AcademicYearLock::withoutLock(fn () => $this->enroll($student, $lastYear, $class, 'active', $regular->id));
        $lastYearEnrollment = Enrollment::where('academic_year_id', $lastYear->id)->firstOrFail();

        $this->enroll($student, $thisYear, $class, 'active', $distance->id);

        $this->assertSame($regular->id, $lastYearEnrollment->fresh()->enrollment_mode_id);
        $this->assertSame(
            $distance->id,
            Enrollment::where('academic_year_id', $thisYear->id)->firstOrFail()->enrollment_mode_id
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Item 5 (Batch 10 / B8): AuditObserver registered on Enrollment
    |--------------------------------------------------------------------------
    */

    public function test_creating_an_enrollment_is_audited(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $this->enroll($student, $year, $class)->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'model' => 'Enrollment',
        ]);
    }

    public function test_updating_an_enrollment_is_audited_with_old_values(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $this->enroll($student, $year, $class);
        $enrollment = Enrollment::where('academic_year_id', $year->id)->firstOrFail();

        $user = $this->authorizedUser();
        $this->actingAs($user)->put(route('dashboard.enrollments.update', $enrollment->id), [
            'academic_year_id' => $year->id,
            'stage_id' => $class->grade->stage_id,
            'grade_id' => $class->grade_id,
            'class_id' => $class->id,
            'status' => 'transferred',
        ])->assertSessionDoesntHaveErrors();

        $log = \App\Models\AuditLog::where('action', 'updated')->where('model', 'Enrollment')->latest()->firstOrFail();
        $this->assertSame('active', $log->old_values['status']);
        $this->assertSame('transferred', $log->new_values['status']);
    }

    public function test_deleting_an_enrollment_is_audited(): void
    {
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $this->enroll($student, $year, $class);
        $enrollment = Enrollment::where('academic_year_id', $year->id)->firstOrFail();

        $user = $this->authorizedUser();
        $this->actingAs($user)->delete(route('dashboard.enrollments.destroy', $enrollment->id));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'deleted',
            'model' => 'Enrollment',
            'model_id' => $enrollment->id,
        ]);
    }

    public function test_a_write_rejected_by_the_academic_year_lock_produces_no_audit_row(): void
    {
        // Documents the interaction with AcademicYearLockObserver (Item 2):
        // creating/updating/deleting fire first and can block the write
        // entirely, so AuditObserver's created/updated/deleted never runs
        // for a rejected attempt — no audit row for a change that never
        // happened.
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Test Student']);
        $lockedYear = AcademicYear::create([
            'name' => '2024 / 2025', 'start_date' => '2024-09-01', 'end_date' => '2025-05-31', 'is_active' => false,
        ]);

        $this->enroll($student, $lockedYear, $class);

        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseMissing('audit_logs', ['model' => 'Enrollment']);
    }
}
