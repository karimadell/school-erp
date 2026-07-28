<?php

namespace Tests\Feature;

use App\Filament\Resources\Curricula\Pages\EditCurriculum;
use App\Filament\Resources\Curricula\RelationManagers\StudentSubjectEnrollmentsRelationManager;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentSubjectEnrollment;
use App\Models\Subject;
use App\Models\User;
use App\Support\AcademicYearLock;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Batch 11 / C2: Student Subject Enrollment — records that a student has
 * individually elected a specific Curriculum row (elective /
 * optional-enrichment only), implemented as a RelationManager on
 * CurriculumResource. Reuses the existing 'manage curriculum' permission
 * — no new permission introduced. creating() only, never updating().
 */
class StudentSubjectEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithRole(string $role): User
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function makeYear(bool $active = true): AcademicYear
    {
        return AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2020-09-01', 'end_date' => '2021-05-31', 'is_active' => $active,
        ]);
    }

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);

        return SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);
    }

    protected function makeSubject(): Subject
    {
        return Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);
    }

    protected function makeElectiveCurriculum(AcademicYear $year, int $gradeId, int $subjectId): Curriculum
    {
        return Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $gradeId, 'subject_id' => $subjectId,
            'weekly_hours' => 2, 'type' => Curriculum::TYPE_ELECTIVE,
        ]);
    }

    /**
     * Enrolls the student in the given class for the given year, active,
     * matching the grade the class belongs to.
     */
    protected function enrollStudent(Student $student, SchoolClass $class, AcademicYear $year): Enrollment
    {
        return Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'stage_id' => $class->grade->stage_id,
            'grade_id' => $class->grade_id,
            'class_id' => $class->id,
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Model / schema
    |--------------------------------------------------------------------------
    */

    public function test_an_election_can_be_created_with_valid_data(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $curriculum = $this->makeElectiveCurriculum($year, $class->grade_id, $subject->id);
        $student = Student::forceCreate(['name' => 'Test Student']);
        $this->enrollStudent($student, $class, $year);

        $election = StudentSubjectEnrollment::create([
            'student_id' => $student->id, 'curriculum_id' => $curriculum->id,
        ]);

        $this->assertDatabaseHas('student_subject_enrollments', [
            'id' => $election->id, 'student_id' => $student->id, 'curriculum_id' => $curriculum->id,
        ]);
    }

    public function test_relations_resolve_correctly(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $curriculum = $this->makeElectiveCurriculum($year, $class->grade_id, $subject->id);
        $student = Student::forceCreate(['name' => 'Test Student']);
        $this->enrollStudent($student, $class, $year);

        $election = StudentSubjectEnrollment::create([
            'student_id' => $student->id, 'curriculum_id' => $curriculum->id,
        ]);

        $this->assertTrue($election->student->is($student));
        $this->assertTrue($election->curriculum->is($curriculum));
        $this->assertTrue($curriculum->studentSubjectEnrollments->contains($election));
        $this->assertSame($year->id, $election->resolveAcademicYear()?->id);
    }

    public function test_the_database_rejects_a_duplicate_election(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $curriculum = $this->makeElectiveCurriculum($year, $class->grade_id, $subject->id);
        $student = Student::forceCreate(['name' => 'Test Student']);
        $this->enrollStudent($student, $class, $year);

        StudentSubjectEnrollment::create([
            'student_id' => $student->id, 'curriculum_id' => $curriculum->id,
        ]);

        $this->expectException(QueryException::class);

        StudentSubjectEnrollment::create([
            'student_id' => $student->id, 'curriculum_id' => $curriculum->id,
        ]);
    }

    public function test_the_same_student_can_elect_a_different_curriculum_row(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subjectOne = $this->makeSubject();
        $subjectTwo = $this->makeSubject();
        $curriculumOne = $this->makeElectiveCurriculum($year, $class->grade_id, $subjectOne->id);
        $curriculumTwo = $this->makeElectiveCurriculum($year, $class->grade_id, $subjectTwo->id);
        $student = Student::forceCreate(['name' => 'Test Student']);
        $this->enrollStudent($student, $class, $year);

        StudentSubjectEnrollment::create(['student_id' => $student->id, 'curriculum_id' => $curriculumOne->id]);
        StudentSubjectEnrollment::create(['student_id' => $student->id, 'curriculum_id' => $curriculumTwo->id]);

        $this->assertDatabaseCount('student_subject_enrollments', 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation — mandatory rejection, grade mismatch
    |--------------------------------------------------------------------------
    */

    public function test_creation_is_rejected_for_a_mandatory_curriculum_row(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $curriculum = Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        $student = Student::forceCreate(['name' => 'Test Student']);
        $this->enrollStudent($student, $class, $year);

        $this->expectException(ValidationException::class);
        StudentSubjectEnrollment::create([
            'student_id' => $student->id, 'curriculum_id' => $curriculum->id,
        ]);
    }

    public function test_creation_succeeds_for_an_optional_enrichment_curriculum_row(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $curriculum = Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 1, 'type' => Curriculum::TYPE_OPTIONAL_ENRICHMENT,
        ]);
        $student = Student::forceCreate(['name' => 'Test Student']);
        $this->enrollStudent($student, $class, $year);

        $election = StudentSubjectEnrollment::create([
            'student_id' => $student->id, 'curriculum_id' => $curriculum->id,
        ]);

        $this->assertDatabaseHas('student_subject_enrollments', ['id' => $election->id]);
    }

    public function test_creation_is_rejected_when_the_students_enrolled_grade_does_not_match(): void
    {
        $year = $this->makeYear();
        $curriculumClass = $this->makeClass();
        $studentClass = $this->makeClass(); // a different grade
        $subject = $this->makeSubject();
        $curriculum = $this->makeElectiveCurriculum($year, $curriculumClass->grade_id, $subject->id);
        $student = Student::forceCreate(['name' => 'Test Student']);
        $this->enrollStudent($student, $studentClass, $year);

        $this->expectException(ValidationException::class);
        StudentSubjectEnrollment::create([
            'student_id' => $student->id, 'curriculum_id' => $curriculum->id,
        ]);
    }

    public function test_creation_is_rejected_when_the_student_has_no_active_enrollment_for_the_curriculums_year(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $curriculum = $this->makeElectiveCurriculum($year, $class->grade_id, $subject->id);
        $student = Student::forceCreate(['name' => 'Test Student']); // no enrollment at all

        $this->expectException(ValidationException::class);
        StudentSubjectEnrollment::create([
            'student_id' => $student->id, 'curriculum_id' => $curriculum->id,
        ]);
    }

    public function test_updating_an_elections_curriculum_to_a_mandatory_row_is_not_validated(): void
    {
        // creating() only, never updating() — per decision, C2 does not
        // reach into updates at all.
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $mandatorySubject = $this->makeSubject();
        $elective = $this->makeElectiveCurriculum($year, $class->grade_id, $subject->id);
        $mandatory = Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $mandatorySubject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        $student = Student::forceCreate(['name' => 'Test Student']);
        $this->enrollStudent($student, $class, $year);

        $election = StudentSubjectEnrollment::create([
            'student_id' => $student->id, 'curriculum_id' => $elective->id,
        ]);

        $election->update(['curriculum_id' => $mandatory->id]);

        $this->assertSame($mandatory->id, $election->fresh()->curriculum_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Observer ordering — AcademicYearLockObserver's rejection surfaces
    | first; the C2 validation only runs once the year is resolved.
    |--------------------------------------------------------------------------
    */

    public function test_a_locked_historical_year_is_reported_before_any_c2_validation(): void
    {
        $year = $this->makeYear(false);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        // Deliberately a mandatory row — if ordering were wrong, this
        // would surface "mandatory, not electable" instead of the
        // correct "year locked" message.
        $curriculum = AcademicYearLock::withoutLock(fn () => Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]));
        $student = Student::forceCreate(['name' => 'Test Student']);

        try {
            StudentSubjectEnrollment::create([
                'student_id' => $student->id, 'curriculum_id' => $curriculum->id,
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(__('academic_years.locked'), $e->errors()['academic_year_lock'][0]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization — reuses 'manage curriculum', admin-only, no new
    | permission
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_view_the_relation_manager(): void
    {
        $user = $this->userWithRole('admin');
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $curriculum = $this->makeElectiveCurriculum($year, $class->grade_id, $subject->id);

        Livewire::actingAs($user)
            ->test(StudentSubjectEnrollmentsRelationManager::class, ['ownerRecord' => $curriculum, 'pageClass' => EditCurriculum::class])
            ->assertSuccessful();
    }

    public function test_a_role_without_manage_curriculum_cannot_create_an_election_via_the_relation_manager(): void
    {
        // 'manage curriculum' is admin-only per the existing seeder
        // policy (CurriculumTest already establishes this) — school-admin
        // holds every other academic-management permission but not this
        // one.
        $user = $this->userWithRole('school-admin');
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $curriculum = $this->makeElectiveCurriculum($year, $class->grade_id, $subject->id);

        Livewire::actingAs($user)
            ->test(StudentSubjectEnrollmentsRelationManager::class, ['ownerRecord' => $curriculum, 'pageClass' => EditCurriculum::class])
            ->assertTableActionHidden('create');

        $this->assertDatabaseCount('student_subject_enrollments', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Relation manager — create + duplicate validation
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_create_an_election_via_the_relation_manager(): void
    {
        $user = $this->userWithRole('admin');
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $curriculum = $this->makeElectiveCurriculum($year, $class->grade_id, $subject->id);
        $student = Student::forceCreate(['name' => 'Test Student']);
        $this->enrollStudent($student, $class, $year);

        Livewire::actingAs($user)
            ->test(StudentSubjectEnrollmentsRelationManager::class, ['ownerRecord' => $curriculum, 'pageClass' => EditCurriculum::class])
            ->callTableAction('create', data: [
                'student_id' => $student->id,
            ]);

        $this->assertDatabaseHas('student_subject_enrollments', [
            'student_id' => $student->id, 'curriculum_id' => $curriculum->id,
        ]);
    }

    public function test_the_relation_manager_surfaces_a_validation_error_for_a_duplicate_election(): void
    {
        $user = $this->userWithRole('admin');
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $curriculum = $this->makeElectiveCurriculum($year, $class->grade_id, $subject->id);
        $student = Student::forceCreate(['name' => 'Test Student']);
        $this->enrollStudent($student, $class, $year);

        StudentSubjectEnrollment::create(['student_id' => $student->id, 'curriculum_id' => $curriculum->id]);

        Livewire::actingAs($user)
            ->test(StudentSubjectEnrollmentsRelationManager::class, ['ownerRecord' => $curriculum, 'pageClass' => EditCurriculum::class])
            ->callTableAction('create', data: [
                'student_id' => $student->id,
            ])
            ->assertHasTableActionErrors(['student_id' => 'unique']);

        $this->assertDatabaseCount('student_subject_enrollments', 1);
    }
}
