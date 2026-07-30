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
use App\Support\TeacherAssignmentRule;
use App\Support\TimetableSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batch 2 / TeacherAssignment Enforcement (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
 * TeacherAssignmentRule tested in isolation, independent of
 * CurriculumAwareTimetableConflictChecker's orchestration and
 * independent of TimetableGrid's wiring (covered in
 * TimetableGridManualEditTest).
 */
class TeacherAssignmentRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function makeYear(bool $active = true): AcademicYear
    {
        return AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => $active,
        ]);
    }

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Primary ' . uniqid()]);
        $grade = Grade::create(['name' => 'Grade ' . uniqid(), 'stage_id' => $stage->id]);

        return SchoolClass::create(['grade_id' => $grade->id, 'code' => 'C-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);
    }

    protected function makeSubject(): Subject
    {
        return Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);
    }

    protected function makeTeacher(): Teacher
    {
        return Teacher::create(['first_name' => 'A', 'last_name' => 'B-' . uniqid(), 'is_active' => true]);
    }

    protected function makeCurriculum(AcademicYear $year, SchoolClass $class, Subject $subject): Curriculum
    {
        // TeacherAssignmentCurriculumObserver (Batch 11 / C6) validates on
        // creation that the assignment's subject is in the assigned
        // class's grade's Curriculum for that year — every
        // TeacherAssignment fixture below needs a matching row.
        return Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
    }

    protected function makeSlot(SchoolClass $class, Subject $subject, Teacher $teacher, array $ignoreIds = []): TimetableSlot
    {
        return new TimetableSlot(
            classId: $class->id, dayId: 1, periodId: 1,
            teacherId: $teacher->id, subjectId: $subject->id, ignoreIds: $ignoreIds,
        );
    }

    public function test_rule_allows_a_teacher_with_a_matching_assignment(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $this->makeCurriculum($year, $class, $subject);

        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $this->assertNull((new TeacherAssignmentRule())->check($this->makeSlot($class, $subject, $teacher)));
    }

    public function test_rule_rejects_a_teacher_with_no_matching_assignment_at_all(): void
    {
        $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();

        $this->assertSame(
            'timetable.teacher_not_assigned',
            (new TeacherAssignmentRule())->check($this->makeSlot($class, $subject, $teacher))
        );
    }

    public function test_rule_rejects_an_assignment_belonging_only_to_an_inactive_year(): void
    {
        // An active year exists (so this isn't the "no active year at
        // all" case below) but the only assignment for this teacher+
        // class+subject sits under a different, inactive year.
        $this->makeYear();
        $inactiveYear = $this->makeYear(active: false);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $this->makeCurriculum($inactiveYear, $class, $subject);

        AcademicYearLock::withoutLock(function () use ($teacher, $class, $subject, $inactiveYear) {
            TeacherAssignment::create([
                'teacher_id' => $teacher->id, 'class_id' => $class->id,
                'subject_id' => $subject->id, 'academic_year_id' => $inactiveYear->id,
            ]);
        });

        $this->assertSame(
            'timetable.teacher_not_assigned',
            (new TeacherAssignmentRule())->check($this->makeSlot($class, $subject, $teacher))
        );
    }

    public function test_rule_rejects_when_no_academic_year_is_active(): void
    {
        // An assignment exists, but tied to a year that isn't the
        // (nonexistent) active one — a distinct failure mode from "wrong
        // specific year" above: there is no "current" year to match at all.
        $year = $this->makeYear(active: false);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $this->makeCurriculum($year, $class, $subject);

        AcademicYearLock::withoutLock(function () use ($teacher, $class, $subject, $year) {
            TeacherAssignment::create([
                'teacher_id' => $teacher->id, 'class_id' => $class->id,
                'subject_id' => $subject->id, 'academic_year_id' => $year->id,
            ]);
        });

        $this->assertSame(
            'timetable.teacher_not_assigned',
            (new TeacherAssignmentRule())->check($this->makeSlot($class, $subject, $teacher))
        );
    }

    public function test_rule_rejects_an_assignment_for_a_different_class(): void
    {
        // TeacherAssignment is class-scoped, not grade-scoped (unlike
        // Curriculum) — an assignment for a different class never
        // authorizes this one, even for the same teacher/subject/year.
        $year = $this->makeYear();
        $class = $this->makeClass();
        $otherClass = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $this->makeCurriculum($year, $otherClass, $subject);

        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $otherClass->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $this->assertSame(
            'timetable.teacher_not_assigned',
            (new TeacherAssignmentRule())->check($this->makeSlot($class, $subject, $teacher))
        );
    }

    public function test_rule_rejects_a_teacher_qualified_via_teacher_subject_but_without_an_assignment(): void
    {
        $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $teacher->subjects()->attach($subject->id); // teacher_subject only, no TeacherAssignment.

        $this->assertSame(
            'timetable.teacher_not_assigned',
            (new TeacherAssignmentRule())->check($this->makeSlot($class, $subject, $teacher))
        );
    }
}
