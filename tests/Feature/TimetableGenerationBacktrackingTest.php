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
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Phase 5A: TimetableGenerationService was rewritten from a single-pass
 * greedy pool to most-constrained-first backtracking, after a real UAT
 * class (11 subjects, 2 of them sharing very little remaining teacher
 * capacity with other already-generated classes) failed unpredictably
 * even though a complete schedule existed — see the diagnostic that
 * preceded this batch. These tests reproduce that failure shape directly
 * and prove the specific guarantees requested for the fix: it finds a
 * schedule whenever one exists, it never relaxes conflict rules to do so,
 * and it still fails cleanly (previous timetable preserved) when no
 * schedule exists at all.
 */
class TimetableGenerationBacktrackingTest extends TestCase
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

    protected function makeSubject(): Subject
    {
        return Subject::create(['code' => 'S-'.uniqid(), 'name_ar' => '', 'name_ru' => 'Subj-'.uniqid(), 'is_active' => true]);
    }

    protected function makeTeacher(): Teacher
    {
        return Teacher::create(['first_name' => 'A', 'last_name' => 'B-'.uniqid(), 'is_active' => true]);
    }

    /** 5 working days (sun..thu), matching the live UAT configuration. */
    protected function makeDays(): Collection
    {
        $codes = ['sun', 'mon', 'tue', 'wed', 'thu'];

        return collect($codes)->map(
            fn ($code, $order) => Day::create(['name' => $code, 'code' => $code, 'order' => $order + 1])
        )->values();
    }

    protected function makePeriods(int $count): Collection
    {
        return collect(range(1, $count))->map(
            fn ($number) => Period::create([
                'number' => $number, 'start_time' => sprintf('%02d:00', 7 + $number), 'end_time' => sprintf('%02d:45', 7 + $number),
            ])
        )->values();
    }

    protected function makeCurriculum(AcademicYear $year, Grade $grade, Subject $subject, int $weeklyHours): Curriculum
    {
        return Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => $weeklyHours, 'type' => Curriculum::TYPE_MANDATORY, 'is_active' => true,
        ]);
    }

    protected function makeAssignment(AcademicYear $year, SchoolClass $class, Subject $subject, Teacher $teacher): TeacherAssignment
    {
        return TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id, 'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);
    }

    protected function makeLesson(SchoolClass $class, Subject $subject, Teacher $teacher, Day $day, Period $period): Timetable
    {
        return Timetable::create([
            'class_id' => $class->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'day_id' => $day->id, 'period_id' => $period->id,
        ]);
    }

    protected function service(): TimetableGenerationService
    {
        return app(TimetableGenerationService::class);
    }

    /**
     * 1 + reproduction test: the exact UAT failure shape — two subjects
     * whose teachers each have only just-enough remaining free slots
     * elsewhere, competing for one shared final slot, plus several fully
     * flexible subjects. A single-pass greedy pool failed this ~86% of
     * the time; backtracking must succeed every time.
     */
    public function test_grade5_style_constrained_dataset_succeeds_every_run(): void
    {
        $year = $this->makeYear();
        $days = $this->makeDays();
        $periods = $this->makePeriods(6); // 30 slots/week, matching UAT

        for ($run = 0; $run < 20; $run++) {
            // Fresh grade/class/subjects/teachers every run — each
            // iteration is an independent scenario of the same shape, not
            // 20 cumulative bookings against the same teachers.
            $grade = $this->makeGrade();
            $class = $this->makeClass($grade);

            $tightSubjectA = $this->makeSubject(); // needs 5, teacher will have 6 free (matches real Русский язык)
            $tightTeacherA = $this->makeTeacher();
            $tightSubjectB = $this->makeSubject(); // needs 5, teacher will have 9 free (matches real Математика)
            $tightTeacherB = $this->makeTeacher();
            $flexSubjects = collect(range(1, 6))->map(fn () => [$this->makeSubject(), $this->makeTeacher()]);

            $this->makeCurriculum($year, $grade, $tightSubjectA, 5);
            $this->makeAssignment($year, $class, $tightSubjectA, $tightTeacherA);
            $this->makeCurriculum($year, $grade, $tightSubjectB, 5);
            $this->makeAssignment($year, $class, $tightSubjectB, $tightTeacherB);

            foreach ($flexSubjects as [$subject, $teacher]) {
                $this->makeCurriculum($year, $grade, $subject, 2);
                $this->makeAssignment($year, $class, $subject, $teacher);
            }

            // Pre-occupy tightTeacherA (24/30, leaving 6 free) and
            // tightTeacherB (21/30, leaving 9 free) elsewhere, at
            // *different* (rotated) slot sets — mirroring the real Grade 5
            // data, where Русский язык (6 free) and Математика (9 free)
            // overlap partially but aren't blocked identically. Blocking
            // both teachers at the exact same slots would make the
            // scenario mathematically infeasible (10 needed from only 6
            // shared slots) rather than merely hard for the algorithm —
            // that's a different test (see the impossible-capacity test
            // below), not this one.
            $otherClassA = $this->makeClass($this->makeGrade());
            $otherClassB = $this->makeClass($this->makeGrade());
            $blockerSubject = $this->makeSubject();
            $allSlots = [];
            foreach ($days as $day) {
                foreach ($periods as $period) {
                    $allSlots[] = [$day, $period];
                }
            }
            // Teacher A free = indices {24..29} (6 slots).
            // Teacher B free = indices {0..5, 24..26} (9 slots) — shares
            // exactly 3 slots with A's free set, unique elsewhere, same
            // partial-overlap shape as the real Русский/Математика data.
            $blockedA = array_slice($allSlots, 0, 24, true);
            $blockedBIndices = array_merge(range(6, 23), range(27, 29));
            $blockedB = array_intersect_key($allSlots, array_flip($blockedBIndices));

            foreach ($blockedA as [$day, $period]) {
                $this->makeLesson($otherClassA, $blockerSubject, $tightTeacherA, $day, $period);
            }
            foreach ($blockedB as [$day, $period]) {
                $this->makeLesson($otherClassB, $blockerSubject, $tightTeacherB, $day, $period);
            }

            $failureKey = $this->service()->generate($class->id, $days, $periods);

            $this->assertNull($failureKey, "Run $run failed: $failureKey");
            $this->assertSame(5, Timetable::where('class_id', $class->id)->where('subject_id', $tightSubjectA->id)->count());
            $this->assertSame(5, Timetable::where('class_id', $class->id)->where('subject_id', $tightSubjectB->id)->count());
        }
    }

    public function test_flexible_subject_does_not_consume_another_subjects_only_slot(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $days = $this->makeDays()->take(2)->values();
        $periods = $this->makePeriods(1);

        // Created first on purpose: the old first-fit shape could put this
        // flexible requirement in the first slot and strand the constrained
        // requirement, despite the second slot being equally valid here.
        $flexibleSubject = $this->makeSubject();
        $flexibleTeacher = $this->makeTeacher();
        $this->makeCurriculum($year, $grade, $flexibleSubject, 1);
        $this->makeAssignment($year, $class, $flexibleSubject, $flexibleTeacher);

        $constrainedSubject = $this->makeSubject();
        $constrainedTeacher = $this->makeTeacher();
        $this->makeCurriculum($year, $grade, $constrainedSubject, 1);
        $this->makeAssignment($year, $class, $constrainedSubject, $constrainedTeacher);

        $otherClass = $this->makeClass($this->makeGrade());
        $blockerSubject = $this->makeSubject();
        $blocker = $this->makeLesson(
            $otherClass,
            $blockerSubject,
            $constrainedTeacher,
            $days->last(),
            $periods->first(),
        );

        $failureKey = $this->service()->generate($class->id, $days, $periods);

        $this->assertNull($failureKey);
        $this->assertDatabaseHas('timetables', [
            'class_id' => $class->id,
            'subject_id' => $constrainedSubject->id,
            'day_id' => $days->first()->id,
            'period_id' => $periods->first()->id,
        ]);
        $this->assertDatabaseHas('timetables', [
            'class_id' => $class->id,
            'subject_id' => $flexibleSubject->id,
            'day_id' => $days->last()->id,
            'period_id' => $periods->first()->id,
        ]);
        $this->assertTrue(Timetable::whereKey($blocker->id)->exists(), 'Another class timetable row was changed.');
    }

    public function test_solver_reverses_an_earlier_placement_when_a_later_domain_becomes_empty(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $days = $this->makeDays();
        $periods = $this->makePeriods(2);
        $blockerSubject = $this->makeSubject();

        $allSlots = [];
        foreach ($days as $day) {
            foreach ($periods as $period) {
                $allSlots[] = [$day, $period];
            }
        }

        // These overlapping domains have a valid matching, but the first
        // deterministic branch assigns subject 1 to slot 0 and later
        // empties subject 2's domain. The search must undo that branch;
        // the valid result puts subject 1 in slot 2 instead.
        $allowedSlotIndexes = [
            [1, 2, 6],
            [0, 2, 3],
            [0, 1],
            [3],
            [0, 1, 3],
            [1, 2, 3, 4, 5],
        ];
        $subjects = [];

        foreach ($allowedSlotIndexes as $allowed) {
            $subject = $this->makeSubject();
            $teacher = $this->makeTeacher();
            $subjects[] = $subject;
            $this->makeCurriculum($year, $grade, $subject, 1);
            $this->makeAssignment($year, $class, $subject, $teacher);

            $blockerClass = $this->makeClass($this->makeGrade());
            foreach ($allSlots as $index => [$day, $period]) {
                if (! in_array($index, $allowed, true)) {
                    $this->makeLesson($blockerClass, $blockerSubject, $teacher, $day, $period);
                }
            }
        }

        $failureKey = $this->service()->generate($class->id, $days, $periods);

        $this->assertNull($failureKey);
        $this->assertDatabaseHas('timetables', [
            'class_id' => $class->id,
            'subject_id' => $subjects[1]->id,
            'day_id' => $allSlots[2][0]->id,
            'period_id' => $allSlots[2][1]->id,
        ]);
        $this->assertSame(6, Timetable::where('class_id', $class->id)->count());
    }

    public function test_teacher_never_appears_in_two_classes_at_the_same_slot(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $classA = $this->makeClass($grade);
        $classB = $this->makeClass($grade);
        $days = $this->makeDays();
        $periods = $this->makePeriods(6);

        $subject = $this->makeSubject();
        $sharedTeacher = $this->makeTeacher();

        $this->makeCurriculum($year, $grade, $subject, 4);
        $this->makeAssignment($year, $classA, $subject, $sharedTeacher);
        $this->makeAssignment($year, $classB, $subject, $sharedTeacher);

        $this->assertNull($this->service()->generate($classA->id, $days, $periods));
        $this->assertNull($this->service()->generate($classB->id, $days, $periods));

        $slotsA = Timetable::where('class_id', $classA->id)->get()->map(fn ($t) => "$t->day_id-$t->period_id");
        $slotsB = Timetable::where('class_id', $classB->id)->get()->map(fn ($t) => "$t->day_id-$t->period_id");

        $this->assertEmpty($slotsA->intersect($slotsB), 'Same teacher was placed in both classes at an overlapping slot.');
        $this->assertCount(4, $slotsA, 'Generating the second class changed the first class timetable.');
        $this->assertCount(4, $slotsB);
    }

    public function test_class_never_has_two_lessons_in_the_same_slot(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $days = $this->makeDays();
        $periods = $this->makePeriods(6);

        foreach (range(1, 4) as $i) {
            $subject = $this->makeSubject();
            $this->makeCurriculum($year, $grade, $subject, 3);
            $this->makeAssignment($year, $class, $subject, $this->makeTeacher());
        }

        $this->assertNull($this->service()->generate($class->id, $days, $periods));

        $lessons = Timetable::where('class_id', $class->id)->get();
        $slotKeys = $lessons->map(fn ($t) => "$t->day_id-$t->period_id");

        $this->assertSame($slotKeys->count(), $slotKeys->unique()->count(), 'Two lessons were placed in the same class slot.');
    }

    public function test_truly_impossible_capacity_still_returns_generation_incomplete(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $days = $this->makeDays();
        $periods = $this->makePeriods(6); // 30 slots total

        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $this->makeCurriculum($year, $grade, $subject, 5);
        $this->makeAssignment($year, $class, $subject, $teacher);

        // Block every slot except 3 for this teacher elsewhere — 5 needed, only 3 possible. Mathematically impossible regardless of algorithm.
        $otherGrade = $this->makeGrade();
        $otherClass = $this->makeClass($otherGrade);
        $blockerSubject = $this->makeSubject();
        $slotIndex = 0;
        foreach ($days as $day) {
            foreach ($periods as $period) {
                if ($slotIndex >= 27) {
                    break 2;
                }
                $this->makeLesson($otherClass, $blockerSubject, $teacher, $day, $period);
                $slotIndex++;
            }
        }

        $failureKey = $this->service()->generate($class->id, $days, $periods);

        $this->assertSame('timetable.generation_incomplete', $failureKey);
        $this->assertSame(0, Timetable::where('class_id', $class->id)->count());
        $this->assertSame(27, Timetable::where('class_id', $otherClass->id)->count());
    }

    public function test_previous_timetable_is_preserved_on_genuine_failure(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $days = $this->makeDays();
        $periods = $this->makePeriods(6);

        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $this->makeCurriculum($year, $grade, $subject, 1);
        $this->makeAssignment($year, $class, $subject, $teacher);

        $existing = $this->makeLesson($class, $subject, $teacher, $days->first(), $periods->first());

        // Now make the curriculum impossible to satisfy: raise required
        // hours for a second subject whose teacher has zero availability.
        $impossibleSubject = $this->makeSubject();
        $impossibleTeacher = $this->makeTeacher();
        $this->makeCurriculum($year, $grade, $impossibleSubject, 5);
        $this->makeAssignment($year, $class, $impossibleSubject, $impossibleTeacher);

        $otherGrade = $this->makeGrade();
        $otherClass = $this->makeClass($otherGrade);
        $blockerSubject = $this->makeSubject();
        $slotIndex = 0;
        foreach ($days as $day) {
            foreach ($periods as $period) {
                if ($slotIndex >= 27) {
                    break 2;
                }
                $this->makeLesson($otherClass, $blockerSubject, $impossibleTeacher, $day, $period);
                $slotIndex++;
            }
        }

        $failureKey = $this->service()->generate($class->id, $days, $periods);

        $this->assertSame('timetable.generation_incomplete', $failureKey);
        // The previous lesson must still be exactly as it was — not
        // deleted, not duplicated.
        $this->assertSame(1, Timetable::where('class_id', $class->id)->count());
        $this->assertTrue(Timetable::whereKey($existing->id)->exists());
        $this->assertSame(27, Timetable::where('class_id', $otherClass->id)->count());
    }

    public function test_weekly_hours_are_satisfied_exactly_on_success(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $days = $this->makeDays();
        $periods = $this->makePeriods(6);

        $hoursBySubject = [3, 4, 2, 5, 1];
        $subjects = collect($hoursBySubject)->map(function ($hours) use ($year, $grade, $class) {
            $subject = $this->makeSubject();
            $this->makeCurriculum($year, $grade, $subject, $hours);
            $this->makeAssignment($year, $class, $subject, $this->makeTeacher());

            return [$subject, $hours];
        });

        $failureKey = $this->service()->generate($class->id, $days, $periods);

        $this->assertNull($failureKey);
        foreach ($subjects as [$subject, $hours]) {
            $this->assertSame($hours, Timetable::where('class_id', $class->id)->where('subject_id', $subject->id)->count());
        }
    }
}
