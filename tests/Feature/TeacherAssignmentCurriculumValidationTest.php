<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Support\AcademicYearLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Batch 11 / C6 (docs/IMPLEMENTATION_READINESS_ROADMAP.md): validates
 * that a newly created TeacherAssignment's subject is actually part of
 * the assigned class's grade's approved Curriculum for that academic
 * year. Validation layer only — validates new writes, rejects invalid
 * data, creates nothing, never modifies Curriculum, never reconciles
 * historical records, introduces no new authorization logic.
 * creating() only, never updating().
 */
class TeacherAssignmentCurriculumValidationTest extends TestCase
{
    use RefreshDatabase;

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

    protected function makeTeacher(): Teacher
    {
        return Teacher::create(['first_name' => 'A', 'last_name' => 'B', 'is_active' => true]);
    }

    public function test_creation_is_rejected_for_a_subject_not_in_the_curriculum(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        // A Curriculum row exists for this grade/year, but for a
        // *different* subject — proves this is a real membership check,
        // not just "does any curriculum exist at all".
        $otherSubject = $this->makeSubject();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $otherSubject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $this->expectException(ValidationException::class);
        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);
    }

    public function test_creation_succeeds_for_a_subject_in_the_curriculum(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $assignment = TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $this->assertDatabaseHas('teacher_assignments', ['id' => $assignment->id]);
    }

    public function test_updating_an_assignments_subject_to_one_not_in_the_curriculum_is_not_validated(): void
    {
        // creating() only, never updating() — per decision, C6 does not
        // reach into updates at all.
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $otherSubject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $assignment = TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $assignment->update(['subject_id' => $otherSubject->id]);

        $this->assertSame($otherSubject->id, $assignment->fresh()->subject_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Observer ordering — AcademicYearLockObserver's rejection surfaces
    | first; the curriculum check only runs once the year is resolved.
    |--------------------------------------------------------------------------
    */

    public function test_an_unresolvable_year_is_reported_before_any_curriculum_check(): void
    {
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();

        // No Curriculum row exists at all here either — if ordering were
        // wrong, this would surface a "not in curriculum" message
        // instead of the correct "year unresolvable" one.
        try {
            TeacherAssignment::create([
                'teacher_id' => $teacher->id, 'class_id' => $class->id,
                'subject_id' => $subject->id, 'academic_year_id' => null,
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(__('academic_years.unresolvable'), $e->errors()['academic_year_lock'][0]);
        }
    }

    public function test_a_locked_historical_year_is_reported_before_any_curriculum_check(): void
    {
        $year = $this->makeYear(false);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        // Deliberately no Curriculum row at all — if ordering were wrong,
        // this would surface a "not in curriculum" message instead of
        // the correct "year locked" one.

        try {
            TeacherAssignment::create([
                'teacher_id' => $teacher->id, 'class_id' => $class->id,
                'subject_id' => $subject->id, 'academic_year_id' => $year->id,
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(__('academic_years.locked'), $e->errors()['academic_year_lock'][0]);
        }
    }
}
