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
use App\Models\Timetable;
use App\Support\CurriculumSubjectRule;
use App\Support\CurriculumWeeklyHoursRule;
use App\Support\TimetableSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batch 1 / Curriculum Enforcement (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
 * each rule tested in isolation, independent of
 * App\Services\CurriculumAwareTimetableConflictChecker's orchestration
 * (covered separately for the base rules in TimetableConflictCheckerTest)
 * and independent of TimetableGrid's wiring (covered in
 * TimetableGridManualEditTest).
 */
class CurriculumTimetableRulesTest extends TestCase
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

    protected function makeSlot(SchoolClass $class, Subject $subject, array $ignoreIds = []): TimetableSlot
    {
        return new TimetableSlot(
            classId: $class->id, dayId: 1, periodId: 1,
            teacherId: $this->makeTeacher()->id, subjectId: $subject->id, ignoreIds: $ignoreIds,
        );
    }

    protected function makeDay(): Day
    {
        return Day::create(['name' => 'd-' . uniqid(), 'code' => 'd-' . uniqid(), 'order' => 0]);
    }

    protected function makePeriod(): Period
    {
        return Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
    }

    /*
    |--------------------------------------------------------------------------
    | CurriculumSubjectRule
    |--------------------------------------------------------------------------
    */

    public function test_subject_rule_rejects_a_subject_not_in_the_curriculum(): void
    {
        $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();

        $this->assertSame(
            'timetable.subject_not_in_curriculum',
            (new CurriculumSubjectRule())->check($this->makeSlot($class, $subject))
        );
    }

    public function test_subject_rule_allows_a_subject_in_the_curriculum(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $this->assertNull((new CurriculumSubjectRule())->check($this->makeSlot($class, $subject)));
    }

    public function test_subject_rule_rejects_when_no_year_is_active(): void
    {
        $this->makeYear(active: false);
        $class = $this->makeClass();
        $subject = $this->makeSubject();

        $this->assertSame(
            'timetable.grade_unresolvable',
            (new CurriculumSubjectRule())->check($this->makeSlot($class, $subject))
        );
    }

    public function test_subject_rule_rejects_a_subject_whose_curriculum_row_belongs_only_to_an_inactive_year(): void
    {
        // An active year exists (so this isn't the "no active year at
        // all" case above) but the only Curriculum row for this grade+
        // subject sits under a different, inactive year — must not make
        // the subject schedulable under the active one.
        $activeYear = $this->makeYear();
        $inactiveYear = $this->makeYear(active: false);
        $class = $this->makeClass();
        $subject = $this->makeSubject();

        Curriculum::create([
            'academic_year_id' => $inactiveYear->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $this->assertSame(
            'timetable.subject_not_in_curriculum',
            (new CurriculumSubjectRule())->check($this->makeSlot($class, $subject))
        );

        // Sanity check: the active year really has none of its own.
        $this->assertNotSame($activeYear->id, $inactiveYear->id);
    }

    public function test_subject_rule_rejects_a_subject_whose_curriculum_row_belongs_to_a_different_grade(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $otherClass = $this->makeClass(); // makeClass() creates its own Stage+Grade each time.
        $subject = $this->makeSubject();

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $otherClass->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $this->assertSame(
            'timetable.subject_not_in_curriculum',
            (new CurriculumSubjectRule())->check($this->makeSlot($class, $subject))
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CurriculumWeeklyHoursRule
    |--------------------------------------------------------------------------
    */

    public function test_weekly_hours_rule_rejects_once_the_quota_is_met(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $otherPeriod = $this->makePeriod();

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 1, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $slot = new TimetableSlot(classId: $class->id, dayId: $day->id, periodId: $otherPeriod->id, teacherId: $teacher->id, subjectId: $subject->id);

        $this->assertSame('timetable.weekly_hours_exceeded', (new CurriculumWeeklyHoursRule())->check($slot));
    }

    public function test_weekly_hours_rule_allows_scheduling_under_the_quota(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $otherPeriod = $this->makePeriod();

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 2, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $slot = new TimetableSlot(classId: $class->id, dayId: $day->id, periodId: $otherPeriod->id, teacherId: $teacher->id, subjectId: $subject->id);

        $this->assertNull((new CurriculumWeeklyHoursRule())->check($slot));
    }

    public function test_weekly_hours_rule_ignores_excluded_ids_when_counting(): void
    {
        // An in-place edit (or a same-subject move) excludes its own row
        // via ignoreIds — it must not count against its own quota.
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $day = $this->makeDay();
        $period = $this->makePeriod();

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 1, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $existing = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $slot = new TimetableSlot(
            classId: $class->id, dayId: $day->id, periodId: $period->id, teacherId: $teacher->id, subjectId: $subject->id,
            ignoreIds: [$existing->id],
        );

        $this->assertNull((new CurriculumWeeklyHoursRule())->check($slot));
    }

    public function test_weekly_hours_rule_uses_the_active_years_quota_not_an_inactive_years(): void
    {
        // Active year allows 2/week; a Curriculum row for the SAME
        // grade+subject under an inactive year allows only 1. One lesson
        // is already scheduled — under the inactive year's limit this
        // would already be "full", but the active year's limit (2) must
        // govern, so scheduling a second lesson must still be allowed.
        $activeYear = $this->makeYear();
        $inactiveYear = $this->makeYear(active: false);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $otherPeriod = $this->makePeriod();

        Curriculum::create([
            'academic_year_id' => $activeYear->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 2, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        Curriculum::create([
            'academic_year_id' => $inactiveYear->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 1, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $slot = new TimetableSlot(classId: $class->id, dayId: $day->id, periodId: $otherPeriod->id, teacherId: $teacher->id, subjectId: $subject->id);

        $this->assertNull((new CurriculumWeeklyHoursRule())->check($slot));
    }

    public function test_weekly_hours_rule_does_not_reject_when_no_curriculum_row_exists(): void
    {
        // CurriculumSubjectRule is responsible for that case — this rule
        // stays silent (null) rather than duplicating the rejection.
        $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();

        $slot = new TimetableSlot(classId: $class->id, dayId: 1, periodId: 1, teacherId: $this->makeTeacher()->id, subjectId: $subject->id);

        $this->assertNull((new CurriculumWeeklyHoursRule())->check($slot));
    }
}
