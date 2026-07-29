<?php

namespace Tests\Feature;

use App\Support\ClassConflictRule;
use App\Support\DuplicateLessonConflictRule;
use App\Support\RoomConflictRule;
use App\Support\TeacherConflictRule;
use App\Support\TimetableSlot;
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
 * Each rule is independent, stateless, and DB-backed — tested here in
 * isolation from TimetableConflictChecker's orchestration.
 */
class TimetableConflictRulesTest extends TestCase
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

    protected function makeDay(): Day
    {
        return Day::create(['name' => 'Day-' . uniqid(), 'code' => 'd-' . uniqid(), 'order' => 0]);
    }

    protected function makePeriod(): Period
    {
        return Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
    }

    /*
    |--------------------------------------------------------------------------
    | ClassConflictRule
    |--------------------------------------------------------------------------
    */

    public function test_class_conflict_rule_detects_the_class_already_booked(): void
    {
        $class = $this->makeClass();
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $existingTeacher = $this->makeTeacher();
        $existingSubject = $this->makeSubject();

        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $existingTeacher->id, 'subject_id' => $existingSubject->id,
        ]);

        $slot = new TimetableSlot(
            classId: $class->id, dayId: $day->id, periodId: $period->id,
            teacherId: $this->makeTeacher()->id, subjectId: $this->makeSubject()->id,
        );

        $this->assertSame('timetable.class_conflict', (new ClassConflictRule())->check($slot));
    }

    public function test_class_conflict_rule_ignores_excluded_ids(): void
    {
        $class = $this->makeClass();
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();

        $existing = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $slot = new TimetableSlot(
            classId: $class->id, dayId: $day->id, periodId: $period->id,
            teacherId: $teacher->id, subjectId: $subject->id, ignoreIds: [$existing->id],
        );

        $this->assertNull((new ClassConflictRule())->check($slot));
    }

    /*
    |--------------------------------------------------------------------------
    | TeacherConflictRule
    |--------------------------------------------------------------------------
    */

    public function test_teacher_conflict_rule_detects_the_teacher_booked_in_a_different_class(): void
    {
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $teacher = $this->makeTeacher();

        Timetable::create([
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $this->makeSubject()->id,
        ]);

        $slot = new TimetableSlot(
            classId: $this->makeClass()->id, dayId: $day->id, periodId: $period->id,
            teacherId: $teacher->id, subjectId: $this->makeSubject()->id,
        );

        $this->assertSame('timetable.teacher_conflict', (new TeacherConflictRule())->check($slot));
    }

    /*
    |--------------------------------------------------------------------------
    | RoomConflictRule
    |--------------------------------------------------------------------------
    */

    public function test_room_conflict_rule_detects_the_room_already_booked(): void
    {
        $day = $this->makeDay();
        $period = $this->makePeriod();

        Timetable::create([
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $this->makeTeacher()->id, 'subject_id' => $this->makeSubject()->id,
            'room' => 'A101',
        ]);

        $slot = new TimetableSlot(
            classId: $this->makeClass()->id, dayId: $day->id, periodId: $period->id,
            teacherId: $this->makeTeacher()->id, subjectId: $this->makeSubject()->id, room: 'A101',
        );

        $this->assertSame('timetable.room_conflict', (new RoomConflictRule())->check($slot));
    }

    public function test_room_conflict_rule_is_skipped_when_no_room_is_set(): void
    {
        $day = $this->makeDay();
        $period = $this->makePeriod();

        Timetable::create([
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $this->makeTeacher()->id, 'subject_id' => $this->makeSubject()->id,
            'room' => 'A101',
        ]);

        $slot = new TimetableSlot(
            classId: $this->makeClass()->id, dayId: $day->id, periodId: $period->id,
            teacherId: $this->makeTeacher()->id, subjectId: $this->makeSubject()->id, room: null,
        );

        $this->assertNull((new RoomConflictRule())->check($slot));
    }

    /*
    |--------------------------------------------------------------------------
    | DuplicateLessonConflictRule
    |--------------------------------------------------------------------------
    */

    public function test_duplicate_lesson_rule_detects_an_exact_match(): void
    {
        $class = $this->makeClass();
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();

        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $slot = new TimetableSlot(
            classId: $class->id, dayId: $day->id, periodId: $period->id,
            teacherId: $teacher->id, subjectId: $subject->id,
        );

        $this->assertSame('timetable.duplicate_lesson_conflict', (new DuplicateLessonConflictRule())->check($slot));
    }

    public function test_duplicate_lesson_rule_does_not_match_a_different_subject_in_the_same_slot(): void
    {
        $class = $this->makeClass();
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $teacher = $this->makeTeacher();

        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $this->makeSubject()->id,
        ]);

        $slot = new TimetableSlot(
            classId: $class->id, dayId: $day->id, periodId: $period->id,
            teacherId: $teacher->id, subjectId: $this->makeSubject()->id,
        );

        $this->assertNull((new DuplicateLessonConflictRule())->check($slot));
    }
}
