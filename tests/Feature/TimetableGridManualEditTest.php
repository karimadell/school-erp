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
use App\Models\Timetable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TimetableGrid::saveLesson()/moveLesson() go through
 * App\Services\CurriculumAwareTimetableConflictChecker — the base
 * conflict rules (Batch: conflict-service extraction) plus, as of
 * Batch 1 / Curriculum Enforcement, Curriculum subject-membership and
 * weekly-hours rules. TimetableController (#1, deprecated) is
 * unaffected — it still resolves the unmodified TimetableConflictChecker.
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

    protected function makeGrid(SchoolClass $class): TimetableGrid
    {
        $grid = new TimetableGrid();
        $grid->classId = $class->id;

        return $grid;
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
}
