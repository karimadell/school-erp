<?php

namespace Tests\Feature;

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
use App\Services\TimetableGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * UAT hardening (item 11): investigated a Cloudflare 504 on
 * POST /dashboard/classes/{class}/timetable/generate. TimetableGenerationService::generate()
 * performed thousands of SQL conflict checks and tentative writes from the
 * recursive search. These tests keep search in memory, enforce a bounded
 * initial-load/final-write query budget, and cover realistic, stress-scale,
 * and unsatisfiable schedules.
 */
class TimetableGenerationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeYear(): AcademicYear
    {
        return AcademicYear::create([
            'name' => 'Year '.uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
    }

    protected function makeGrade(): Grade
    {
        $stage = Stage::create(['name' => 'Primary '.uniqid()]);

        return Grade::create(['name' => 'Grade '.uniqid(), 'stage_id' => $stage->id]);
    }

    protected function makeClass(Grade $grade): SchoolClass
    {
        return SchoolClass::create([
            'grade_id' => $grade->id, 'code' => 'C-'.uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true,
        ]);
    }

    protected function makeDays(): \Illuminate\Support\Collection
    {
        $codes = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

        return collect($codes)->map(
            fn ($code, $order) => Day::create(['name' => $code, 'code' => $code, 'order' => $order])
        )->values();
    }

    protected function makePeriods(int $count): \Illuminate\Support\Collection
    {
        return collect(range(1, $count))->map(
            fn ($number) => Period::create([
                'number' => $number, 'start_time' => sprintf('%02d:00', 7 + $number), 'end_time' => sprintf('%02d:45', 7 + $number),
            ])
        )->values();
    }

    /**
     * Representative of a real UAT class: a handful of subjects, one
     * teacher per subject, weekly hours that comfortably fit the week.
     */
    public function test_generation_completes_quickly_for_a_realistic_curriculum(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $days = $this->makeDays();
        $periods = $this->makePeriods(7);

        foreach (range(1, 8) as $i) {
            $subject = Subject::create(['code' => "S{$i}-".uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);
            $teacher = Teacher::create(['first_name' => 'T', 'last_name' => "T{$i}-".uniqid(), 'is_active' => true]);

            Curriculum::create([
                'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
                'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
            ]);
            TeacherAssignment::create([
                'teacher_id' => $teacher->id, 'class_id' => $class->id,
                'subject_id' => $subject->id, 'academic_year_id' => $year->id,
            ]);
        }

        $start = microtime(true);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^(insert|update|delete)/i', ltrim($query->sql))) {
                $writes[] = $query->sql;
            }
        });
        $service = app(TimetableGenerationService::class);
        $failureKey = $service->generate($class->id, $days, $periods);
        $elapsed = microtime(true) - $start;
        $telemetry = $service->lastTelemetry();

        $this->assertNull($failureKey);
        $this->assertSame(24, Timetable::where('class_id', $class->id)->count());
        $this->assertLessThan(5.0, $elapsed, 'Realistic-scale generation must not approach request-timeout territory.');
        $this->assertNotNull($telemetry);
        $this->assertTrue($telemetry->succeeded);
        $this->assertLessThan(30, $telemetry->queryCount, 'Search query count must stay independent of solver nodes.');
        $this->assertGreaterThanOrEqual(24, $telemetry->nodes);
        $this->assertCount(2, $writes, 'Only the final class delete and one bulk insert may write during successful generation.');
        $this->assertStringStartsWith('delete', strtolower(ltrim($writes[0])));
        $this->assertStringStartsWith('insert', strtolower(ltrim($writes[1])));
    }

    public function test_legacy_workflow_uses_periods_one_through_six_and_never_period_seven(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $days = $this->makeDays();
        $periods = $this->makePeriods(7);
        $subject = Subject::create(['code' => 'S-'.uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'T', 'last_name' => 'T-'.uniqid(), 'is_active' => true]);

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 30, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $failureKey = app(TimetableGenerationService::class)->generate($class->id, $days, $periods);

        $this->assertNull($failureKey);
        $this->assertSame(30, Timetable::where('class_id', $class->id)->count());
        $this->assertSame(
            [1, 2, 3, 4, 5, 6],
            Timetable::where('class_id', $class->id)
                ->join('periods', 'periods.id', '=', 'timetables.period_id')
                ->distinct()->orderBy('periods.number')->pluck('periods.number')->all(),
        );
        $this->assertSame(0, Timetable::where('class_id', $class->id)->where('period_id', $periods->last()->id)->count());
    }

    /**
     * Deliberately larger than any real UAT class (20 subjects, 3
     * candidate teachers each) to prove the bound holds well past normal
     * scale, not just at it.
     */
    public function test_generation_completes_quickly_at_a_scale_larger_than_any_realistic_class(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $days = $this->makeDays();
        $periods = $this->makePeriods(7);

        foreach (range(1, 20) as $i) {
            $subject = Subject::create(['code' => "S{$i}-".uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);

            Curriculum::create([
                'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
                'weekly_hours' => 1, 'type' => Curriculum::TYPE_MANDATORY,
            ]);

            foreach (range(1, 3) as $t) {
                $teacher = Teacher::create(['first_name' => 'T', 'last_name' => "T{$i}-{$t}-".uniqid(), 'is_active' => true]);
                TeacherAssignment::create([
                    'teacher_id' => $teacher->id, 'class_id' => $class->id,
                    'subject_id' => $subject->id, 'academic_year_id' => $year->id,
                ]);
            }
        }

        $start = microtime(true);
        $failureKey = app(TimetableGenerationService::class)->generate($class->id, $days, $periods);
        $elapsed = microtime(true) - $start;

        $this->assertNull($failureKey);
        $this->assertSame(20, Timetable::where('class_id', $class->id)->count());
        $this->assertLessThan(5.0, $elapsed, 'Stress-scale generation must still terminate well within request-timeout territory.');
    }

    /**
     * A curriculum that numerically cannot fit the available slots must
     * fail fast with a controlled Russian error — not hang, and not leave
     * a partial timetable behind.
     */
    public function test_unsatisfiable_curriculum_terminates_quickly_with_no_partial_writes(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $days = $this->makeDays();
        $periods = $this->makePeriods(1); // 5 working slots/week available

        $subject = Subject::create(['code' => 'S-'.uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'T', 'last_name' => 'T-'.uniqid(), 'is_active' => true]);

        // 30 weekly hours can never fit into 5 available working-day slots.
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 30, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $start = microtime(true);
        $failureKey = app(TimetableGenerationService::class)->generate($class->id, $days, $periods);
        $elapsed = microtime(true) - $start;

        $this->assertSame('timetable.generation_insufficient_slots', $failureKey);
        $this->assertLessThan(2.0, $elapsed, 'An unsatisfiable curriculum must fail fast, not hang.');
        $this->assertSame(0, Timetable::where('class_id', $class->id)->count());
    }

    /**
     * A busy-teacher conflict discovered mid-placement must roll the whole
     * transaction back — the class keeps zero rows, never a partial set.
     */
    public function test_a_conflict_discovered_mid_generation_leaves_no_partial_timetable(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $days = $this->makeDays();
        $periods = $this->makePeriods(1);

        $subject = Subject::create(['code' => 'S-'.uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'T', 'last_name' => 'T-'.uniqid(), 'is_active' => true]);

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 2, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        // The teacher is already booked elsewhere on every working day, so
        // no working-day slot for this class can ever be filled.
        $otherClass = $this->makeClass($grade);
        foreach ($days as $day) {
            Timetable::create([
                'class_id' => $otherClass->id, 'day_id' => $day->id, 'period_id' => $periods->first()->id,
                'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            ]);
        }

        $failureKey = app(TimetableGenerationService::class)->generate($class->id, $days, $periods);

        $this->assertNotNull($failureKey);
        $this->assertSame(0, Timetable::where('class_id', $class->id)->count());
        // The other class's pre-existing lessons must survive the rollback untouched.
        $this->assertSame(7, Timetable::where('class_id', $otherClass->id)->count());
    }
}
