<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Quarter;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\Subject;
use App\Support\AcademicYearLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Batch 11 / C3 (docs/IMPLEMENTATION_READINESS_ROADMAP.md): validates
 * that a newly created Exam/StudentGrade's subject is actually part of
 * the resolved grade's approved Curriculum. Validation layer only —
 * validates new writes, rejects invalid data, creates nothing, never
 * modifies Curriculum, never reconciles historical records, introduces
 * no new authorization logic. creating() only, never updating().
 */
class CurriculumValidationTest extends TestCase
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

    protected function makeQuarter(AcademicYear $year): Quarter
    {
        return Quarter::create(['academic_year_id' => $year->id, 'name' => 'Q-' . uniqid(), 'order' => 1]);
    }

    /*
    |--------------------------------------------------------------------------
    | Exam
    |--------------------------------------------------------------------------
    */

    public function test_exam_creation_is_rejected_for_a_subject_not_in_the_curriculum(): void
    {
        $year = $this->makeYear();
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        // A Curriculum row exists for this grade/year, but for a
        // *different* subject — proves this is a real membership check,
        // not just "does any curriculum exist at all".
        $otherSubject = $this->makeSubject();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $otherSubject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $this->expectException(ValidationException::class);
        Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);
    }

    public function test_exam_creation_succeeds_for_a_subject_in_the_curriculum(): void
    {
        $year = $this->makeYear();
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $exam = Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);

        $this->assertDatabaseHas('exams', ['id' => $exam->id]);
    }

    public function test_updating_an_exams_subject_to_one_not_in_the_curriculum_is_not_validated(): void
    {
        // creating() only, never updating() — per decision, C3 does not
        // reach into updates at all.
        $year = $this->makeYear();
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $otherSubject = $this->makeSubject();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $exam = Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);

        $exam->update(['subject_id' => $otherSubject->id]);

        $this->assertSame($otherSubject->id, $exam->fresh()->subject_id);
    }

    /*
    |--------------------------------------------------------------------------
    | StudentGrade — via exam_id
    |--------------------------------------------------------------------------
    */

    public function test_student_grade_creation_is_rejected_for_a_subject_not_in_the_curriculum_via_exam(): void
    {
        $year = $this->makeYear();
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        // Exam itself is for the curriculum-approved subject...
        $exam = Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);
        $student = Student::forceCreate(['name' => 'S', 'class_id' => $class->id]);
        $otherSubject = $this->makeSubject();

        // ...but the grade row itself claims a different, non-curriculum
        // subject_id (StudentGrade.subject_id is independent of the
        // linked Exam's own subject_id at the schema level).
        $this->expectException(ValidationException::class);
        StudentGrade::create([
            'student_id' => $student->id, 'exam_id' => $exam->id, 'subject_id' => $otherSubject->id, 'score' => 90,
        ]);
    }

    public function test_student_grade_creation_succeeds_for_a_subject_in_the_curriculum_via_exam(): void
    {
        $year = $this->makeYear();
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        $exam = Exam::create([
            'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
        ]);
        $student = Student::forceCreate(['name' => 'S', 'class_id' => $class->id]);

        $grade = StudentGrade::create([
            'student_id' => $student->id, 'exam_id' => $exam->id, 'subject_id' => $subject->id, 'score' => 90,
        ]);

        $this->assertDatabaseHas('student_grades', ['id' => $grade->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | StudentGrade — quarter-only, no exam_id (grade resolved via student's class)
    |--------------------------------------------------------------------------
    */

    public function test_student_grade_creation_is_rejected_for_a_subject_not_in_the_curriculum_via_class(): void
    {
        $year = $this->makeYear();
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $otherSubject = $this->makeSubject();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        $student = Student::forceCreate(['name' => 'S', 'class_id' => $class->id]);

        $this->expectException(ValidationException::class);
        StudentGrade::create([
            'student_id' => $student->id, 'quarter_id' => $quarter->id, 'subject_id' => $otherSubject->id, 'score' => 90,
        ]);
    }

    public function test_student_grade_creation_succeeds_for_a_subject_in_the_curriculum_via_class(): void
    {
        $year = $this->makeYear();
        $quarter = $this->makeQuarter($year);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        $student = Student::forceCreate(['name' => 'S', 'class_id' => $class->id]);

        $grade = StudentGrade::create([
            'student_id' => $student->id, 'quarter_id' => $quarter->id, 'subject_id' => $subject->id, 'score' => 90,
        ]);

        $this->assertDatabaseHas('student_grades', ['id' => $grade->id]);
    }

    public function test_student_grade_creation_fails_closed_when_the_student_has_no_class(): void
    {
        $year = $this->makeYear();
        $quarter = $this->makeQuarter($year);
        $subject = $this->makeSubject();
        $student = Student::forceCreate(['name' => 'S']); // no class_id

        $this->expectException(ValidationException::class);
        StudentGrade::create([
            'student_id' => $student->id, 'quarter_id' => $quarter->id, 'subject_id' => $subject->id, 'score' => 90,
        ]);
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

        // No quarter_id at all — academic_year_id can never resolve.
        // AcademicYearLockObserver must reject this before
        // CurriculumValidationObserver ever gets a chance to run
        // (and, since no Curriculum row exists at all here either, a
        // wrongly-ordered check would produce a confusing
        // "not in curriculum" message instead of the correct
        // "year unresolvable" one).
        try {
            Exam::create(['name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(__('academic_years.unresolvable'), $e->errors()['academic_year_lock'][0]);
        }
    }

    public function test_a_locked_historical_year_is_reported_before_any_curriculum_check(): void
    {
        $year = $this->makeYear(false);
        $quarter = AcademicYearLock::withoutLock(fn () => $this->makeQuarter($year));
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        // Deliberately no Curriculum row at all — if ordering were wrong,
        // this would surface a "not in curriculum" message instead of
        // the correct "year locked" one.

        try {
            Exam::create([
                'name' => 'Midterm', 'subject_id' => $subject->id, 'class_id' => $class->id, 'quarter_id' => $quarter->id,
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(__('academic_years.locked'), $e->errors()['academic_year_lock'][0]);
        }
    }
}
