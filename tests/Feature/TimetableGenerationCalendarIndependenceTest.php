<?php

namespace Tests\Feature;

use App\Models\AcademicCalendar;
use App\Models\AcademicYear;
use App\Models\BellSchedule;
use App\Models\Curriculum;
use App\Models\Day;
use App\Models\Grade;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UAT bug investigation follow-up: the report assumed the Smart Timetable
 * Generator's "Недостаточно доступных уроков…" failure was caused by
 * AcademicCalendar.default_bell_schedule_id not persisting (the bug fixed
 * in this same batch — see DashboardAcademicCalendarTest's new "UAT bug
 * fix" section).
 *
 * Tracing the exact error string
 * ('timetable.generation_insufficient_slots') to its only source
 * (App\Services\TimetableGenerationService::generate(), line ~89) shows the
 * generator computes availableSlots from App\Models\Day (filtered by
 * App\Models\TimetableSetting's non_working_days) and App\Models\Period —
 * the legacy flat scheduling tables — never from AcademicCalendar or
 * BellSchedule. This test proves that independence directly: a curriculum
 * that fits 5 working Day rows × 6 Period rows (30 slots) for a 12-hour
 * weekly plan succeeds whether the class's AcademicCalendar has no default
 * Bell Schedule at all, or one with a period count that would be far too
 * small if it were consulted — because it never is.
 */
class TimetableGenerationCalendarIndependenceTest extends TestCase
{
    use RefreshDatabase;

    protected function adminUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    /** 7 Day rows (sun..sat) — TimetableSetting defaults non_working_days to fri+sat, leaving 5 working days. */
    protected function seedDays(): void
    {
        foreach ([
            ['code' => 'sun', 'order' => 0, 'name' => 'Воскресенье'],
            ['code' => 'mon', 'order' => 1, 'name' => 'Понедельник'],
            ['code' => 'tue', 'order' => 2, 'name' => 'Вторник'],
            ['code' => 'wed', 'order' => 3, 'name' => 'Среда'],
            ['code' => 'thu', 'order' => 4, 'name' => 'Четверг'],
            ['code' => 'fri', 'order' => 5, 'name' => 'Пятница'],
            ['code' => 'sat', 'order' => 6, 'name' => 'Суббота'],
        ] as $day) {
            Day::create($day);
        }
    }

    /** 6 Period rows — matches the UAT's "6 active periods per day" premise. */
    protected function seedPeriods(): void
    {
        foreach ([
            [1, '08:00', '08:45'], [2, '08:50', '09:35'], [3, '09:40', '10:25'],
            [4, '10:30', '11:15'], [5, '11:20', '12:05'], [6, '12:10', '12:55'],
        ] as [$number, $start, $end]) {
            Period::create(['number' => $number, 'start_time' => $start, 'end_time' => $end]);
        }
    }

    protected function makeClassWithCurriculum(AcademicYear $year, int $totalWeeklyHours, int $subjectCount): SchoolClass
    {
        $stage = Stage::create(['name' => 'Stage ' . uniqid()]);
        $grade = Grade::create(['name' => 'Grade ' . uniqid(), 'stage_id' => $stage->id]);
        $class = SchoolClass::create([
            'grade_id' => $grade->id, 'code' => 'C-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true,
        ]);

        $hoursPerSubject = intdiv($totalWeeklyHours, $subjectCount);

        for ($i = 0; $i < $subjectCount; $i++) {
            $subject = Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);
            $teacher = Teacher::create(['first_name' => 'T', 'last_name' => 'Teacher-' . uniqid(), 'is_active' => true]);

            Curriculum::create([
                'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
                'weekly_hours' => $hoursPerSubject, 'type' => Curriculum::TYPE_MANDATORY,
            ]);
            TeacherAssignment::create([
                'teacher_id' => $teacher->id, 'class_id' => $class->id,
                'subject_id' => $subject->id, 'academic_year_id' => $year->id,
            ]);
        }

        return $class;
    }

    public function test_generation_succeeds_when_academic_calendar_has_no_default_bell_schedule_at_all(): void
    {
        $admin = $this->adminUser();
        $this->seedDays();
        $this->seedPeriods();

        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        // Academic Calendar exists but default_bell_schedule_id is left null —
        // exactly the pre-fix persisted state the UAT bug produced.
        AcademicCalendar::create(['academic_year_id' => $year->id, 'weekly_days_off' => ['fri', 'sat']]);

        $class = $this->makeClassWithCurriculum($year, totalWeeklyHours: 12, subjectCount: 4);

        $response = $this->actingAs($admin)->post(route('dashboard.classes.timetable.generate', $class));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');
        $this->assertSame(12, \App\Models\Timetable::where('class_id', $class->id)->count());
    }

    public function test_generation_outcome_is_unaffected_by_a_bell_schedule_with_far_fewer_periods_than_the_legacy_tables(): void
    {
        $admin = $this->adminUser();
        $this->seedDays();
        $this->seedPeriods(); // 6 legacy Period rows — what the generator actually reads.

        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        // A same-year, active, default Bell Schedule with only ONE period —
        // if the generator consulted it, 5 days x 1 period = 5 slots would
        // be far short of the 12-hour plan and generation would fail.
        $schedule = BellSchedule::create([
            'academic_year_id' => $year->id, 'name' => 'Sparse', 'shift' => 1, 'is_default' => true, 'is_active' => true,
        ]);
        $schedule->periods()->create([
            'period_number' => 1, 'starts_at' => '08:00', 'ends_at' => '08:45', 'break_after_minutes' => 0,
        ]);

        $calendar = AcademicCalendar::create(['academic_year_id' => $year->id, 'weekly_days_off' => ['fri', 'sat']]);
        $calendar->update(['default_bell_schedule_id' => $schedule->id]);
        $this->assertSame($schedule->id, $calendar->fresh()->default_bell_schedule_id);

        $class = $this->makeClassWithCurriculum($year, totalWeeklyHours: 12, subjectCount: 4);

        $response = $this->actingAs($admin)->post(route('dashboard.classes.timetable.generate', $class));

        // Generation succeeds on the legacy Day/Period capacity (30 slots),
        // proving the sparse Bell Schedule (5 slots) was never consulted.
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');
        $this->assertSame(12, \App\Models\Timetable::where('class_id', $class->id)->count());
    }
}
