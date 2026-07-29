<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassResource\Pages\TimetableGrid;
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
 * TimetableGrid::saveLesson()/moveLesson() now go through the same
 * App\Services\TimetableConflictChecker as TimetableController, instead
 * of their own independent, weaker (teacher-only) check. No coverage
 * existed for either method before this batch.
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

    protected function makeGrid(SchoolClass $class): TimetableGrid
    {
        $grid = new TimetableGrid();
        $grid->classId = $class->id;

        return $grid;
    }

    public function test_save_lesson_is_rejected_when_the_teacher_is_already_booked_elsewhere(): void
    {
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();

        Timetable::create([
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $class = $this->makeClass();
        $grid = $this->makeGrid($class);
        $grid->selectedSubject = [$day->id => [$period->id => $subject->id]];
        $grid->selectedTeacher = [$day->id => [$period->id => $teacher->id]];

        $grid->saveLesson($day->id, $period->id);

        $this->assertSame(0, Timetable::where('class_id', $class->id)->count());
    }

    public function test_save_lesson_succeeds_when_nothing_conflicts(): void
    {
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();

        $grid = $this->makeGrid($class);
        $grid->selectedSubject = [$day->id => [$period->id => $subject->id]];
        $grid->selectedTeacher = [$day->id => [$period->id => $teacher->id]];

        $grid->saveLesson($day->id, $period->id);

        $this->assertSame(1, Timetable::where('class_id', $class->id)->count());
    }

    public function test_move_lesson_rejects_a_teacher_conflict_at_the_target_slot(): void
    {
        $class = $this->makeClass();
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $sourcePeriod = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $targetPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);
        $teacher = $this->makeTeacher();

        $moving = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacher->id, 'subject_id' => $this->makeSubject()->id,
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
        $class = $this->makeClass();
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $sourcePeriod = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $targetPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);
        $teacher = $this->makeTeacher();

        $moving = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacher->id, 'subject_id' => $this->makeSubject()->id,
        ]);
        $targetSubject = $this->makeSubject();
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
}
