<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassResource\Pages\TimetableGrid;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\Day;
use App\Models\Grade;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\Timetable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TimetableGrid::saveLesson()/moveLesson() go through
 * App\Services\CurriculumAwareTimetableConflictChecker — the base
 * conflict rules (Batch: conflict-service extraction), Curriculum
 * subject-membership/weekly-hours rules (Batch 1), and, as of Batch 2 /
 * TeacherAssignment Enforcement, the TeacherAssignmentRule. Every
 * fixture below that expects saveLesson()/moveLesson() to actually
 * succeed (or to be rejected by a *specific* later rule) now needs a
 * matching TeacherAssignment row, or TeacherAssignmentRule would reject
 * it first for the wrong reason. TimetableController (#1, deprecated)
 * is unaffected — it still resolves the unmodified TimetableConflictChecker.
 */
class TimetableGridManualEditTest extends TestCase
{
    use RefreshDatabase;

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

    protected function makeYear(): AcademicYear
    {
        return AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
    }

    protected function makeCurriculum(AcademicYear $year, SchoolClass $class, Subject $subject, int $weeklyHours = 10): Curriculum
    {
        return Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => $weeklyHours, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
    }

    protected function makeAssignment(AcademicYear $year, SchoolClass $class, Subject $subject, Teacher $teacher): TeacherAssignment
    {
        return TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);
    }

    protected function makeGrid(SchoolClass $class): TimetableGrid
    {
        $grid = new TimetableGrid();
        $grid->classId = $class->id;

        return $grid;
    }

    protected function makeDay(string $code, int $order = 0): Day
    {
        return Day::create(['name' => $code, 'code' => $code, 'order' => $order]);
    }

    public function test_save_lesson_is_rejected_when_the_teacher_is_already_booked_elsewhere(): void
    {
        $year = $this->makeYear();
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();

        Timetable::create([
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $class = $this->makeClass();
        $this->makeCurriculum($year, $class, $subject);
        $this->makeAssignment($year, $class, $subject, $teacher);
        $grid = $this->makeGrid($class);
        $grid->selectedSubject = [$day->id => [$period->id => $subject->id]];
        $grid->selectedTeacher = [$day->id => [$period->id => $teacher->id]];

        $grid->saveLesson($day->id, $period->id);

        $this->assertSame(0, Timetable::where('class_id', $class->id)->count());
    }

    public function test_save_lesson_succeeds_when_nothing_conflicts(): void
    {
        $year = $this->makeYear();
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        $this->makeCurriculum($year, $class, $subject);
        $this->makeAssignment($year, $class, $subject, $teacher);

        $grid = $this->makeGrid($class);
        $grid->selectedSubject = [$day->id => [$period->id => $subject->id]];
        $grid->selectedTeacher = [$day->id => [$period->id => $teacher->id]];

        $grid->saveLesson($day->id, $period->id);

        $this->assertSame(1, Timetable::where('class_id', $class->id)->count());
    }

    public function test_move_lesson_rejects_a_teacher_conflict_at_the_target_slot(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $sourcePeriod = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $targetPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        $this->makeCurriculum($year, $class, $subject);
        $this->makeAssignment($year, $class, $subject, $teacher);

        $moving = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);
        // A different class, same teacher, at the target slot.
        Timetable::create([
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $targetPeriod->id,
            'teacher_id' => $teacher->id, 'subject_id' => $this->makeSubject()->id,
        ]);

        $grid = $this->makeGrid($class);
        $grid->dragLessonId = $moving->id;
        $grid->moveLesson($day->id, $targetPeriod->id);

        $this->assertSame($sourcePeriod->id, $moving->fresh()->period_id);
    }

    public function test_move_lesson_swaps_two_lessons_taught_by_the_same_teacher(): void
    {
        // Regression: the old code only excluded the dragged lesson's own
        // id from its teacher-conflict check, so swapping two lessons the
        // same teacher gives to the same class (e.g. a double period)
        // falsely reported a conflict, since the target lesson (same
        // teacher) was never excluded. Ignoring both rows fixes this.
        $year = $this->makeYear();
        $class = $this->makeClass();
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $sourcePeriod = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $targetPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        $targetSubject = $this->makeSubject();
        $this->makeCurriculum($year, $class, $subject);
        $this->makeCurriculum($year, $class, $targetSubject);
        $this->makeAssignment($year, $class, $subject, $teacher);
        $this->makeAssignment($year, $class, $targetSubject, $teacher);

        $moving = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);
        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $targetPeriod->id,
            'teacher_id' => $teacher->id, 'subject_id' => $targetSubject->id,
        ]);

        $grid = $this->makeGrid($class);
        $grid->dragLessonId = $moving->id;
        $grid->moveLesson($day->id, $targetPeriod->id);

        // The target lesson's row is replaced (deleted + recreated) by
        // the swap, so its identity, not its original model instance, is
        // what's asserted here — see moveLesson()'s doc comment.
        $this->assertSame($targetPeriod->id, $moving->fresh()->period_id);
        $this->assertDatabaseHas('timetables', [
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacher->id, 'subject_id' => $targetSubject->id,
        ]);
        $this->assertSame(2, Timetable::where('class_id', $class->id)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Batch 1 / Curriculum Enforcement
    |--------------------------------------------------------------------------
    */

    public function test_curriculum_subjects_for_class_only_returns_subjects_in_the_active_years_curriculum(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $inCurriculum = $this->makeSubject();
        $notInCurriculum = $this->makeSubject();
        $this->makeCurriculum($year, $class, $inCurriculum);

        $grid = $this->makeGrid($class);
        $subjectIds = $grid->curriculumSubjectsForClass()->pluck('id')->all();

        $this->assertContains($inCurriculum->id, $subjectIds);
        $this->assertNotContains($notInCurriculum->id, $subjectIds);
    }

    public function test_save_lesson_is_rejected_for_a_subject_not_in_the_curriculum(): void
    {
        $this->makeYear();
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        // Deliberately no Curriculum row for $subject at all.

        $grid = $this->makeGrid($class);
        $grid->selectedSubject = [$day->id => [$period->id => $subject->id]];
        $grid->selectedTeacher = [$day->id => [$period->id => $teacher->id]];

        $grid->saveLesson($day->id, $period->id);

        $this->assertSame(0, Timetable::where('class_id', $class->id)->count());
    }

    public function test_save_lesson_is_rejected_once_the_weekly_hours_quota_is_met(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        $this->makeCurriculum($year, $class, $subject, weeklyHours: 1);

        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $periodOne = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $periodTwo = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);

        // One lesson of $subject already scheduled — the quota (1) is met.
        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $periodOne->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $grid = $this->makeGrid($class);
        $grid->selectedSubject = [$day->id => [$periodTwo->id => $subject->id]];
        $grid->selectedTeacher = [$day->id => [$periodTwo->id => $teacher->id]];

        $grid->saveLesson($day->id, $periodTwo->id);

        $this->assertSame(1, Timetable::where('class_id', $class->id)->where('subject_id', $subject->id)->count());
    }

    public function test_save_lesson_succeeds_when_within_the_weekly_hours_quota(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        $this->makeCurriculum($year, $class, $subject, weeklyHours: 2);
        $this->makeAssignment($year, $class, $subject, $teacher);

        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $periodOne = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $periodTwo = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);

        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $periodOne->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $grid = $this->makeGrid($class);
        $grid->selectedSubject = [$day->id => [$periodTwo->id => $subject->id]];
        $grid->selectedTeacher = [$day->id => [$periodTwo->id => $teacher->id]];

        $grid->saveLesson($day->id, $periodTwo->id);

        $this->assertSame(2, Timetable::where('class_id', $class->id)->where('subject_id', $subject->id)->count());
    }

    public function test_save_lesson_is_rejected_when_no_academic_year_is_active(): void
    {
        // Deliberately no active year at all (not even an inactive one
        // with a matching Curriculum row) — CurriculumContext::forClass()
        // cannot resolve, so CurriculumSubjectRule must fail closed.
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();

        $grid = $this->makeGrid($class);
        $grid->selectedSubject = [$day->id => [$period->id => $subject->id]];
        $grid->selectedTeacher = [$day->id => [$period->id => $teacher->id]];

        $grid->saveLesson($day->id, $period->id);

        $this->assertSame(0, Timetable::where('class_id', $class->id)->count());
    }

    public function test_move_lesson_is_not_blocked_by_its_own_slot_counting_against_the_quota(): void
    {
        // A same-subject move doesn't change the total scheduled count for
        // that subject — it must never be rejected by the weekly-hours
        // rule merely for existing.
        $year = $this->makeYear();
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        $this->makeCurriculum($year, $class, $subject, weeklyHours: 1);
        $this->makeAssignment($year, $class, $subject, $teacher);

        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $sourcePeriod = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $targetPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);

        $moving = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $grid = $this->makeGrid($class);
        $grid->dragLessonId = $moving->id;
        $grid->moveLesson($day->id, $targetPeriod->id);

        $this->assertSame($targetPeriod->id, $moving->fresh()->period_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Batch 2 / TeacherAssignment Enforcement
    |--------------------------------------------------------------------------
    */

    public function test_assigned_teachers_for_only_returns_teachers_with_a_matching_assignment(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $assigned = $this->makeTeacher();
        $unassigned = $this->makeTeacher();
        $unassigned->subjects()->attach($subject->id); // teacher_subject only.
        $this->makeCurriculum($year, $class, $subject);
        $this->makeAssignment($year, $class, $subject, $assigned);

        $grid = $this->makeGrid($class);
        $teacherIds = $grid->assignedTeachersFor($subject->id)->pluck('id')->all();

        $this->assertContains($assigned->id, $teacherIds);
        $this->assertNotContains($unassigned->id, $teacherIds);
    }

    public function test_save_lesson_is_rejected_when_the_teacher_has_no_assignment(): void
    {
        $year = $this->makeYear();
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        $this->makeCurriculum($year, $class, $subject);
        // Deliberately no TeacherAssignment for $teacher at all.

        $grid = $this->makeGrid($class);
        $grid->selectedSubject = [$day->id => [$period->id => $subject->id]];
        $grid->selectedTeacher = [$day->id => [$period->id => $teacher->id]];

        $grid->saveLesson($day->id, $period->id);

        $this->assertSame(0, Timetable::where('class_id', $class->id)->count());
    }

    public function test_save_lesson_edit_is_rejected_when_the_replacement_teacher_has_no_assignment(): void
    {
        $year = $this->makeYear();
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $this->makeCurriculum($year, $class, $subject);
        $assignedTeacher = $this->makeTeacher();
        $this->makeAssignment($year, $class, $subject, $assignedTeacher);
        $unassignedTeacher = $this->makeTeacher();

        $existing = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $assignedTeacher->id, 'subject_id' => $subject->id,
        ]);

        $grid = $this->makeGrid($class);
        $grid->selectedSubject = [$day->id => [$period->id => $subject->id]];
        $grid->selectedTeacher = [$day->id => [$period->id => $unassignedTeacher->id]];

        $grid->saveLesson($day->id, $period->id);

        $this->assertSame($assignedTeacher->id, $existing->fresh()->teacher_id);
    }

    public function test_move_lesson_is_rejected_when_the_dragged_lessons_teacher_has_no_assignment(): void
    {
        // The lesson already exists with an unassigned teacher (e.g. the
        // assignment was later revoked) — moving it must still be
        // enforced, not treated as a structural pass-through.
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $sourcePeriod = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $targetPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);

        $moving = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $grid = $this->makeGrid($class);
        $grid->dragLessonId = $moving->id;
        $grid->moveLesson($day->id, $targetPeriod->id);

        $this->assertSame($sourcePeriod->id, $moving->fresh()->period_id);
    }

    public function test_move_lesson_swap_does_not_falsely_reject_when_both_lessons_remain_assigned(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $sourcePeriod = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $targetPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);
        $teacherA = $this->makeTeacher();
        $teacherB = $this->makeTeacher();
        $subjectA = $this->makeSubject();
        $subjectB = $this->makeSubject();
        $this->makeCurriculum($year, $class, $subjectA);
        $this->makeCurriculum($year, $class, $subjectB);
        $this->makeAssignment($year, $class, $subjectA, $teacherA);
        $this->makeAssignment($year, $class, $subjectB, $teacherB);

        $moving = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacherA->id, 'subject_id' => $subjectA->id,
        ]);
        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $targetPeriod->id,
            'teacher_id' => $teacherB->id, 'subject_id' => $subjectB->id,
        ]);

        $grid = $this->makeGrid($class);
        $grid->dragLessonId = $moving->id;
        $grid->moveLesson($day->id, $targetPeriod->id);

        $this->assertSame($targetPeriod->id, $moving->fresh()->period_id);
        $this->assertDatabaseHas('timetables', [
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacherB->id, 'subject_id' => $subjectB->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Batch 3 / Working Days Enforcement
    |--------------------------------------------------------------------------
    */

    public function test_save_lesson_rejects_a_non_working_day(): void
    {
        $year = $this->makeYear();
        $friday = $this->makeDay('fri');
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        $this->makeCurriculum($year, $class, $subject);
        $this->makeAssignment($year, $class, $subject, $teacher);

        $grid = $this->makeGrid($class);
        $grid->selectedSubject = [$friday->id => [$period->id => $subject->id]];
        $grid->selectedTeacher = [$friday->id => [$period->id => $teacher->id]];

        $grid->saveLesson($friday->id, $period->id);

        $this->assertSame(0, Timetable::where('class_id', $class->id)->count());
    }

    public function test_save_lesson_edit_of_an_existing_lesson_rejects_a_non_working_day(): void
    {
        // A lesson already occupies a Friday cell (e.g. pre-existing data
        // from before this rule existed) — re-saving/editing that same
        // cell must still be rejected, not grandfathered in.
        $year = $this->makeYear();
        $friday = $this->makeDay('fri');
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $this->makeCurriculum($year, $class, $subject);
        $teacher = $this->makeTeacher();
        $this->makeAssignment($year, $class, $subject, $teacher);

        $existing = Timetable::create([
            'class_id' => $class->id, 'day_id' => $friday->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $otherSubject = $this->makeSubject();
        $this->makeCurriculum($year, $class, $otherSubject);
        $this->makeAssignment($year, $class, $otherSubject, $teacher);

        $grid = $this->makeGrid($class);
        $grid->selectedSubject = [$friday->id => [$period->id => $otherSubject->id]];
        $grid->selectedTeacher = [$friday->id => [$period->id => $teacher->id]];

        $grid->saveLesson($friday->id, $period->id);

        $this->assertSame($subject->id, $existing->fresh()->subject_id);
    }

    public function test_move_lesson_rejects_a_non_working_target_day(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $workingDay = $this->makeDay('mon');
        $friday = $this->makeDay('fri');
        $sourcePeriod = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $targetPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        $this->makeCurriculum($year, $class, $subject);
        $this->makeAssignment($year, $class, $subject, $teacher);

        $moving = Timetable::create([
            'class_id' => $class->id, 'day_id' => $workingDay->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $grid = $this->makeGrid($class);
        $grid->dragLessonId = $moving->id;
        $grid->moveLesson($friday->id, $targetPeriod->id);

        $this->assertSame($workingDay->id, $moving->fresh()->day_id);
        $this->assertSame($sourcePeriod->id, $moving->fresh()->period_id);
    }

    public function test_move_lesson_swap_targeting_a_non_working_day_is_rejected(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $workingDay = $this->makeDay('mon');
        $friday = $this->makeDay('fri');
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $targetPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);
        $teacherA = $this->makeTeacher();
        $teacherB = $this->makeTeacher();
        $subjectA = $this->makeSubject();
        $subjectB = $this->makeSubject();
        $this->makeCurriculum($year, $class, $subjectA);
        $this->makeCurriculum($year, $class, $subjectB);
        $this->makeAssignment($year, $class, $subjectA, $teacherA);
        $this->makeAssignment($year, $class, $subjectB, $teacherB);

        $moving = Timetable::create([
            'class_id' => $class->id, 'day_id' => $workingDay->id, 'period_id' => $period->id,
            'teacher_id' => $teacherA->id, 'subject_id' => $subjectA->id,
        ]);
        $target = Timetable::create([
            'class_id' => $class->id, 'day_id' => $friday->id, 'period_id' => $targetPeriod->id,
            'teacher_id' => $teacherB->id, 'subject_id' => $subjectB->id,
        ]);

        $grid = $this->makeGrid($class);
        $grid->dragLessonId = $moving->id;
        $grid->moveLesson($friday->id, $targetPeriod->id);

        // Neither lesson moved — the swap never happens.
        $this->assertSame($workingDay->id, $moving->fresh()->day_id);
        $this->assertSame($period->id, $moving->fresh()->period_id);
        $this->assertSame($friday->id, $target->fresh()->day_id);
        $this->assertSame($targetPeriod->id, $target->fresh()->period_id);
    }

    public function test_move_lesson_swap_between_two_working_day_slots_remains_valid(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $day = $this->makeDay('mon');
        $sourcePeriod = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $targetPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);
        $teacherA = $this->makeTeacher();
        $teacherB = $this->makeTeacher();
        $subjectA = $this->makeSubject();
        $subjectB = $this->makeSubject();
        $this->makeCurriculum($year, $class, $subjectA);
        $this->makeCurriculum($year, $class, $subjectB);
        $this->makeAssignment($year, $class, $subjectA, $teacherA);
        $this->makeAssignment($year, $class, $subjectB, $teacherB);

        $moving = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacherA->id, 'subject_id' => $subjectA->id,
        ]);
        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $targetPeriod->id,
            'teacher_id' => $teacherB->id, 'subject_id' => $subjectB->id,
        ]);

        $grid = $this->makeGrid($class);
        $grid->dragLessonId = $moving->id;
        $grid->moveLesson($day->id, $targetPeriod->id);

        $this->assertSame($targetPeriod->id, $moving->fresh()->period_id);
        $this->assertDatabaseHas('timetables', [
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacherB->id, 'subject_id' => $subjectB->id,
        ]);
    }

    public function test_working_day_rule_fails_before_curriculum_or_teacher_assignment_rules(): void
    {
        // Every other condition is also invalid (no Curriculum row, no
        // TeacherAssignment) — WorkingDayRule, registered first, must be
        // the one that actually fires.
        $friday = $this->makeDay('fri');
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        // Deliberately no active year, no Curriculum, no TeacherAssignment.

        $grid = $this->makeGrid($class);
        $grid->selectedSubject = [$friday->id => [$period->id => $subject->id]];
        $grid->selectedTeacher = [$friday->id => [$period->id => $teacher->id]];

        $grid->saveLesson($friday->id, $period->id);

        $this->assertSame(0, Timetable::where('class_id', $class->id)->count());

        $slot = new \App\Support\TimetableSlot(
            classId: $class->id, dayId: $friday->id, periodId: $period->id,
            teacherId: $teacher->id, subjectId: $subject->id,
        );
        $result = app(\App\Services\CurriculumAwareTimetableConflictChecker::class)->check($slot);

        $this->assertSame('timetable.non_working_day', $result->first());
    }
}
