<?php

namespace Tests\Feature\Teacher;

use App\Filament\Teacher\Pages\TeacherTimetable;
use App\Models\Day;
use App\Models\Grade;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Batch 8: TeacherTimetable was already correctly scoped by teacher_id —
 * no logic change here. It was, however, silently broken by the
 * previously-missing teachers.user_id column (fixed in this batch),
 * since Teacher::where('user_id', Auth::id()) would have thrown a SQL
 * error against the real database. This is a regression check that the
 * page still works exactly as before, now that the column exists.
 */
class TeacherTimetableTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teacher_sees_only_their_own_timetable_lessons(): void
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A', 'name_ar' => 'a', 'name_ru' => 'A']);
        $subject = Subject::create(['code' => 'MATH', 'name_ar' => 'رياضيات', 'name_ru' => 'Математика']);
        $day = new Day();
        $day->name = 'Понедельник';
        $day->save();
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $otherPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);

        $user = User::factory()->create();
        $teacher = Teacher::create(['user_id' => $user->id, 'first_name' => 'Anna', 'last_name' => 'Ivanova', 'is_active' => true]);
        $otherTeacher = Teacher::create(['first_name' => 'Boris', 'last_name' => 'Petrov', 'is_active' => true]);

        $ownLesson = Timetable::create([
            'class_id' => $class->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'day_id' => $day->id, 'period_id' => $period->id,
        ]);
        Timetable::create([
            'class_id' => $class->id, 'subject_id' => $subject->id, 'teacher_id' => $otherTeacher->id,
            'day_id' => $day->id, 'period_id' => $otherPeriod->id,
        ]);

        $component = Livewire::actingAs($user)->test(TeacherTimetable::class);

        $lessons = $component->get('lessons');
        $this->assertCount(1, $lessons);
        $this->assertSame($ownLesson->id, $lessons->first()->id);
    }
}
