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
use App\Models\TimetableSetting;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * D1 Phase 2 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, row D1): first
 * test coverage for TimetableGrid::generateTimetable() — none existed
 * before this batch. Deliberately bypasses TimetableGrid::mount(): that
 * method also calls Period::orderBy('order'), a separate, pre-existing
 * broken query (the `periods` table has a `number` column, never an
 * `order` column) unrelated to this batch's scope. Tests build the
 * page's public properties directly instead, exercising
 * generateTimetable() in isolation.
 *
 * Batch 4 / Timetable Permissions: generateTimetable() now abort_unless
 * the acting user has 'manage timetable'. setUp() authenticates a user
 * with that permission for every test in this class — see
 * TimetableGridAuthorizationTest for the permission checks themselves.
 */
class TimetableGridGenerateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsTimetableManager();
    }

    protected function actingAsTimetableManager(): User
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'manage timetable']);
        $user->givePermissionTo('manage timetable');
        $this->actingAs($user);

        return $user;
    }

    protected function makeYear(bool $active = true): AcademicYear
    {
        return AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => $active,
        ]);
    }

    protected function makeGrade(): Grade
    {
        $stage = Stage::create(['name' => 'Primary ' . uniqid()]);

        return Grade::create(['name' => 'Grade ' . uniqid(), 'stage_id' => $stage->id]);
    }

    protected function makeClass(Grade $grade, bool $active = true): SchoolClass
    {
        return SchoolClass::create([
            'grade_id' => $grade->id, 'code' => 'C-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => $active,
        ]);
    }

    protected function makeSubject(bool $active = true): Subject
    {
        return Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => $active]);
    }

    protected function makeTeacher(bool $active = true): Teacher
    {
        return Teacher::create(['first_name' => 'A', 'last_name' => 'B-' . uniqid(), 'is_active' => $active]);
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

    protected function makeGrid(SchoolClass $class, $days, $periods): TimetableGrid
    {
        $grid = new TimetableGrid();
        $grid->classId = $class->id;
        $grid->days = $days;
        $grid->periods = $periods;

        return $grid;
    }

    protected function makeCurriculum(
        AcademicYear $year,
        Grade $grade,
        Subject $subject,
        int $weeklyHours,
        bool $active = true,
    ): Curriculum {
        return Curriculum::create([
            'academic_year_id' => $year->id,
            'grade_id' => $grade->id,
            'subject_id' => $subject->id,
            'weekly_hours' => $weeklyHours,
            'type' => Curriculum::TYPE_MANDATORY,
            'is_active' => $active,
        ]);
    }

    protected function makeAssignment(
        AcademicYear $year,
        SchoolClass $class,
        Subject $subject,
        Teacher $teacher,
    ): TeacherAssignment {
        return TeacherAssignment::create([
            'teacher_id' => $teacher->id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
        ]);
    }

    protected function makeLesson(
        SchoolClass $class,
        Subject $subject,
        Teacher $teacher,
        Day $day,
        Period $period,
    ): Timetable {
        return Timetable::create([
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'day_id' => $day->id,
            'period_id' => $period->id,
        ]);
    }

    public function test_weekly_hours_come_from_curriculum_not_a_subject_column(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 5, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $grid = $this->makeGrid($class, $this->makeDays(), $this->makePeriods(6));
        $grid->generateTimetable();

        $this->assertSame(5, Timetable::where('class_id', $class->id)->where('subject_id', $subject->id)->count());

        // Batch 12 / Localization: generateTimetable()'s success
        // Notification title was hardcoded English ("Smart Timetable
        // Generated") — now uses the new timetable.generated_success key.
        Notification::assertNotified(__('timetable.generated_success'));
    }

    public function test_curriculum_lookup_is_scoped_to_the_classs_grade(): void
    {
        $year = $this->makeYear();
        $gradeA = $this->makeGrade();
        $gradeB = $this->makeGrade();
        $classA = $this->makeClass($gradeA);
        $classB = $this->makeClass($gradeB);
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();

        // Curriculum + TeacherAssignment exist only for Grade A.
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $gradeA->id, 'subject_id' => $subject->id,
            'weekly_hours' => 3, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $classA->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $days = $this->makeDays();
        $periods = $this->makePeriods(6);

        $this->makeGrid($classA, $days, $periods)->generateTimetable();
        $this->makeGrid($classB, $days, $periods)->generateTimetable();

        $this->assertGreaterThan(0, Timetable::where('class_id', $classA->id)->count());
        $this->assertSame(0, Timetable::where('class_id', $classB->id)->count());
    }

    public function test_default_configuration_never_schedules_on_friday_or_saturday(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 30, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $grid = $this->makeGrid($class, $this->makeDays(), $this->makePeriods(7));
        $grid->generateTimetable();

        $scheduledDayCodes = Timetable::where('class_id', $class->id)
            ->join('days', 'days.id', '=', 'timetables.day_id')
            ->pluck('days.code')
            ->unique();

        $this->assertNotContains('fri', $scheduledDayCodes);
        $this->assertNotContains('sat', $scheduledDayCodes);
        $this->assertGreaterThan(0, $scheduledDayCodes->count());
    }

    public function test_a_custom_non_working_days_setting_changes_which_days_are_used(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();

        // Exactly one lesson per working day (6 days once only Sunday is
        // excluded, 1 period/day) so the pool can't be exhausted before
        // Saturday is ever reached — proves Saturday is actually usable
        // under this custom setting, not just "never ran out first".
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 6, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        TimetableSetting::current()->update(['non_working_days' => ['sun']]);

        $grid = $this->makeGrid($class, $this->makeDays(), $this->makePeriods(1));
        $grid->generateTimetable();

        $scheduledDayCodes = Timetable::where('class_id', $class->id)
            ->join('days', 'days.id', '=', 'timetables.day_id')
            ->pluck('days.code')
            ->unique();

        $this->assertNotContains('sun', $scheduledDayCodes);
        $this->assertContains('fri', $scheduledDayCodes);
        $this->assertContains('sat', $scheduledDayCodes);
    }

    public function test_teacher_selection_uses_teacher_assignment_not_the_qualification_pivot(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $subject = $this->makeSubject();

        $qualifiedOnly = $this->makeTeacher();
        $qualifiedOnly->subjects()->attach($subject->id); // teacher_subject only, no TeacherAssignment

        $assigned = $this->makeTeacher();

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 4, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        TeacherAssignment::create([
            'teacher_id' => $assigned->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $grid = $this->makeGrid($class, $this->makeDays(), $this->makePeriods(6));
        $grid->generateTimetable();

        $teacherIds = Timetable::where('class_id', $class->id)->pluck('teacher_id')->unique();

        $this->assertGreaterThan(0, $teacherIds->count());
        $this->assertSame([$assigned->id], $teacherIds->values()->all());
    }

    public function test_missing_active_academic_year_preserves_the_existing_timetable_and_reports_failure(): void
    {
        $year = $this->makeYear(active: false);
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $days = $this->makeDays();
        $periods = $this->makePeriods(6);

        \App\Support\AcademicYearLock::withoutLock(function () use ($year, $grade, $subject, $teacher, $class) {
            Curriculum::create([
                'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
                'weekly_hours' => 5, 'type' => Curriculum::TYPE_MANDATORY,
            ]);
            TeacherAssignment::create([
                'teacher_id' => $teacher->id, 'class_id' => $class->id,
                'subject_id' => $subject->id, 'academic_year_id' => $year->id,
            ]);
        });

        $existing = $this->makeLesson($class, $subject, $teacher, $days->first(), $periods->first());

        $grid = $this->makeGrid($class, $days, $periods);
        $grid->generateTimetable();

        $this->assertDatabaseHas('timetables', ['id' => $existing->id]);
        Notification::assertNotified(__('timetable.generation_no_active_year'));
        Notification::assertNotNotified(__('timetable.generated_success'));
    }

    public function test_successful_generation_replaces_only_the_current_class_with_a_complete_timetable(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $currentClass = $this->makeClass($grade);
        $otherClass = $this->makeClass($grade);
        $oldSubject = $this->makeSubject();
        $newSubject = $this->makeSubject();
        $oldTeacher = $this->makeTeacher();
        $newTeacher = $this->makeTeacher();
        $otherTeacher = $this->makeTeacher();
        $days = $this->makeDays();
        $periods = $this->makePeriods(3);

        $this->makeCurriculum($year, $grade, $newSubject, 3);
        $this->makeAssignment($year, $currentClass, $newSubject, $newTeacher);

        $oldCurrentLesson = $this->makeLesson($currentClass, $oldSubject, $oldTeacher, $days->first(), $periods->first());
        $otherLesson = $this->makeLesson($otherClass, $oldSubject, $otherTeacher, $days->get(1), $periods->first());

        $this->makeGrid($currentClass, $days, $periods)->generateTimetable();

        $this->assertDatabaseMissing('timetables', ['id' => $oldCurrentLesson->id]);
        $this->assertDatabaseHas('timetables', ['id' => $otherLesson->id]);
        $this->assertSame(3, Timetable::where('class_id', $currentClass->id)->where('subject_id', $newSubject->id)->count());
        $this->assertSame(1, Timetable::where('class_id', $otherClass->id)->count());
        Notification::assertNotified(__('timetable.generated_success'));
    }

    public function test_missing_curriculum_preserves_the_existing_timetable(): void
    {
        $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $days = $this->makeDays();
        $periods = $this->makePeriods(2);
        $existing = $this->makeLesson($class, $subject, $teacher, $days->first(), $periods->first());

        $this->makeGrid($class, $days, $periods)->generateTimetable();

        $this->assertDatabaseHas('timetables', ['id' => $existing->id]);
        Notification::assertNotified(__('timetable.generation_no_curriculum'));
        Notification::assertNotNotified(__('timetable.generated_success'));
    }

    public function test_missing_assigned_teacher_preserves_the_existing_timetable(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $subject = $this->makeSubject();
        $oldSubject = $this->makeSubject();
        $oldTeacher = $this->makeTeacher();
        $days = $this->makeDays();
        $periods = $this->makePeriods(2);
        $this->makeCurriculum($year, $grade, $subject, 1);
        $existing = $this->makeLesson($class, $oldSubject, $oldTeacher, $days->first(), $periods->first());

        $this->makeGrid($class, $days, $periods)->generateTimetable();

        $this->assertDatabaseHas('timetables', ['id' => $existing->id]);
        Notification::assertNotified(__('timetable.generation_missing_teacher'));
        Notification::assertNotNotified(__('timetable.generated_success'));
    }

    public function test_insufficient_capacity_preserves_the_existing_timetable(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $days = $this->makeDays();
        $periods = $this->makePeriods(1);
        TimetableSetting::current()->update(['non_working_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat']]);
        $this->makeCurriculum($year, $grade, $subject, 2);
        $this->makeAssignment($year, $class, $subject, $teacher);
        $existing = $this->makeLesson($class, $subject, $teacher, $days->get(1), $periods->first());

        $this->makeGrid($class, $days, $periods)->generateTimetable();

        $this->assertDatabaseHas('timetables', ['id' => $existing->id]);
        Notification::assertNotified(__('timetable.generation_insufficient_slots'));
        Notification::assertNotNotified(__('timetable.generated_success'));
    }

    public function test_incomplete_checker_validated_placement_rolls_back_the_replacement(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $currentClass = $this->makeClass($grade);
        $otherClass = $this->makeClass($grade);
        $subject = $this->makeSubject();
        $oldSubject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $oldTeacher = $this->makeTeacher();
        $days = $this->makeDays();
        $periods = $this->makePeriods(1);
        TimetableSetting::current()->update(['non_working_days' => ['tue', 'wed', 'thu', 'fri', 'sat']]);
        $this->makeCurriculum($year, $grade, $subject, 2);
        $this->makeAssignment($year, $currentClass, $subject, $teacher);

        $existing = $this->makeLesson($currentClass, $oldSubject, $oldTeacher, $days->get(2), $periods->first());
        $this->makeLesson($otherClass, $subject, $teacher, $days->get(0), $periods->first());
        $this->makeLesson($otherClass, $subject, $teacher, $days->get(1), $periods->first());

        $this->makeGrid($currentClass, $days, $periods)->generateTimetable();

        $this->assertDatabaseHas('timetables', ['id' => $existing->id]);
        $this->assertSame(0, Timetable::where('class_id', $currentClass->id)->where('subject_id', $subject->id)->count());
        Notification::assertNotified(__('timetable.generation_incomplete'));
        Notification::assertNotNotified(__('timetable.generated_success'));
    }

    public function test_inactive_class_preserves_its_existing_timetable(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade, active: false);
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $days = $this->makeDays();
        $periods = $this->makePeriods(1);
        $this->makeCurriculum($year, $grade, $subject, 1);
        $this->makeAssignment($year, $class, $subject, $teacher);
        $existing = $this->makeLesson($class, $subject, $teacher, $days->first(), $periods->first());

        $this->makeGrid($class, $days, $periods)->generateTimetable();

        $this->assertDatabaseHas('timetables', ['id' => $existing->id]);
        Notification::assertNotified(__('timetable.generation_inactive_class'));
    }

    public function test_inactive_curriculum_is_rejected_without_replacing_the_timetable(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $days = $this->makeDays();
        $periods = $this->makePeriods(1);
        $this->makeCurriculum($year, $grade, $subject, 1, active: false);
        $existing = $this->makeLesson($class, $subject, $teacher, $days->first(), $periods->first());

        $this->makeGrid($class, $days, $periods)->generateTimetable();

        $this->assertDatabaseHas('timetables', ['id' => $existing->id]);
        Notification::assertNotified(__('timetable.generation_no_curriculum'));
    }

    public function test_inactive_subject_is_rejected_without_replacing_the_timetable(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $subject = $this->makeSubject(active: false);
        $oldSubject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $days = $this->makeDays();
        $periods = $this->makePeriods(1);
        $this->makeCurriculum($year, $grade, $subject, 1);
        $existing = $this->makeLesson($class, $oldSubject, $teacher, $days->first(), $periods->first());

        $this->makeGrid($class, $days, $periods)->generateTimetable();

        $this->assertDatabaseHas('timetables', ['id' => $existing->id]);
        Notification::assertNotified(__('timetable.generation_inactive_subject'));
    }

    public function test_inactive_assigned_teacher_is_rejected_without_replacing_the_timetable(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $class = $this->makeClass($grade);
        $subject = $this->makeSubject();
        $oldSubject = $this->makeSubject();
        $teacher = $this->makeTeacher(active: false);
        $oldTeacher = $this->makeTeacher();
        $days = $this->makeDays();
        $periods = $this->makePeriods(1);
        $this->makeCurriculum($year, $grade, $subject, 1);
        $this->makeAssignment($year, $class, $subject, $teacher);
        $existing = $this->makeLesson($class, $oldSubject, $oldTeacher, $days->first(), $periods->first());

        $this->makeGrid($class, $days, $periods)->generateTimetable();

        $this->assertDatabaseHas('timetables', ['id' => $existing->id]);
        $this->assertDatabaseMissing('timetables', ['class_id' => $class->id, 'teacher_id' => $teacher->id]);
        Notification::assertNotified(__('timetable.generation_missing_teacher'));
    }
}
