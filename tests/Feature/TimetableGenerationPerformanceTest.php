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
use Tests\TestCase;

/**
 * UAT hardening (item 11): investigated a Cloudflare 504 on
 * POST /dashboard/classes/{class}/timetable/generate. TimetableGenerationService::generate()
 * was read end-to-end and confirmed to already be bounded — every loop is
 * capped by small collections (working days, periods, curriculum subjects,
 * assigned teachers per subject), and the whole operation runs inside one
 * DB::transaction(). These tests turn that investigation into permanent
 * regression coverage instead of the throwaway scripts used to verify it,
 * at both a realistic scale and a deliberately larger one, plus the
 * unsatisfiable-schedule termination path. No change was made to the
 * generation algorithm itself — the 504 is not reproducible from the
 * algorithm's own runtime.
 */
class TimetableGenerationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeYear(): AcademicYear
    {
        return AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
    }

    protected function makeGrade(): Grade
    {
        $stage = Stage::create(['name' => 'Primary ' . uniqid()]);

        return Grade::create(['name' => 'Grade ' . uniqid(), 'stage_id' => $stage->id]);
    }

    protected function makeClass(Grade $grade): SchoolClass
    {
        return SchoolClass::create([
            'grade_id' => $grade->id, 'code' => 'C-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true,
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
            $subject = Subject::create(['code' => "S{$i}-" . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);
            $teacher = Teacher::create(['first_name' => 'T', 'last_name' => "T{$i}-" . uniqid(), 'is_active' => true]);

            Curriculum::create([
                'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
                'weekly_hours' => 4, 'type' => Curriculum::TYPE_MANDATORY,
            ]);
            TeacherAssignment::create([
                'teacher_id' => $teacher->id, 'class_id' => $class->id,
                'subject_id' => $subject->id, 'academic_year_id' => $year->id,
            ]);
        }

        $start = microtime(true);
        $failureKey = app(TimetableGenerationService::class)->generate($class->id, $days, $periods);
        $elapsed = microtime(true) - $start;

        $this->assertNull($failureKey);
        $this->assertSame(32, Timetable::where('class_id', $class->id)->count());
        $this->assertLessThan(5.0, $elapsed, 'Realistic-scale generation must not approach request-timeout territory.');
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
            $subject = Subject::create(['code' => "S{$i}-" . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);

            Curriculum::create([
                'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
                'weekly_hours' => 1, 'type' => Curriculum::TYPE_MANDATORY,
            ]);

            foreach (range(1, 3) as $t) {
                $teacher = Teacher::create(['first_name' => 'T', 'last_name' => "T{$i}-{$t}-" . uniqid(), 'is_active' => true]);
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

        $subject = Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'T', 'last_name' => 'T-' . uniqid(), 'is_active' => true]);

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

        $subject = Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'T', 'last_name' => 'T-' . uniqid(), 'is_active' => true]);

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
