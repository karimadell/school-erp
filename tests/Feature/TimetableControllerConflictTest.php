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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * TimetableController now orchestrates
 * App\Services\CurriculumAwareTimetableConflictChecker instead of running
 * its own conflict SQL — these tests exercise the real store/update/move
 * routes to prove the wiring, not just the service in isolation (covered
 * separately in TimetableConflictRulesTest / TimetableConflictCheckerTest /
 * CurriculumTimetableRulesTest). No coverage existed for these routes
 * before this batch.
 *
 * Batch 9: store/update/move are now manage-gated
 * (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md §4), so every test user here
 * needs 'manage timetable' to keep proving conflict-checker wiring rather
 * than incidentally proving a 403 — authorization itself is covered
 * separately in TimetableControllerAuthorizationTest.
 *
 * Batch 10: TimetableController (#1, deprecated) now resolves
 * CurriculumAwareTimetableConflictChecker — the same rule set the
 * canonical TimetableGrid already uses — instead of the unmodified
 * TimetableConflictChecker. Every fixture below whose store/update/move
 * call is expected to reach a *specific* base conflict rule (or to
 * succeed) now needs a matching Curriculum row and TeacherAssignment, or
 * WorkingDayRule/CurriculumSubjectRule/CurriculumWeeklyHoursRule/
 * TeacherAssignmentRule (which all run first) would reject it for the
 * wrong reason. Rows created directly via Timetable::create() (the
 * "already occupied" side of a conflict) bypass the checker entirely and
 * need no such fixture.
 */
class TimetableControllerConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'manage timetable']);
        $user->givePermissionTo('manage timetable');

        return $user;
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

    protected function makeDay(): Day
    {
        return Day::create(['name' => 'Day-' . uniqid(), 'code' => 'd-' . uniqid(), 'order' => 0]);
    }

    protected function makePeriod(): Period
    {
        return Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
    }

    protected function makeYear(bool $active = true): AcademicYear
    {
        return AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => $active,
        ]);
    }

    protected function makeCurriculum(AcademicYear $year, SchoolClass $class, Subject $subject, int $weeklyHours = 10): Curriculum
    {
        return Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => $weeklyHours, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
    }

    protected function makeAssignment(AcademicYear $year, SchoolClass $class, Subject $subject, Teacher $teacher): TeacherAssignment
    {
        return TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);
    }

    /**
     * Makes the (class, subject, teacher) combination fully eligible to be
     * scheduled: Curriculum membership with a generous weekly-hours quota
     * and a matching TeacherAssignment. Used for the side of a test that
     * actually goes through the store/update/move route, so the rule under
     * test (not an earlier curriculum/assignment/working-day rule) is the
     * one that fires or lets the request through.
     */
    protected function makeEligible(AcademicYear $year, SchoolClass $class, Subject $subject, Teacher $teacher): void
    {
        $this->makeCurriculum($year, $class, $subject);
        $this->makeAssignment($year, $class, $subject, $teacher);
    }

    public function test_store_rejects_a_duplicate_lesson_before_the_broader_class_conflict(): void
    {
        $user = $this->makeUser();
        $year = $this->makeYear();
        $class = $this->makeClass();
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        $this->makeEligible($year, $class, $subject, $teacher);

        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        // Exact same class+day+period+subject+teacher again — Duplicate
        // must win over the broader Class rule (see rule ordering in
        // AppServiceProvider).
        $response = $this->actingAs($user)->post(route('dashboard.timetable.store'), [
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        ]);

        $response->assertSessionHasErrors(['error' => __('timetable.duplicate_lesson_conflict')]);
        $this->assertSame(1, Timetable::count());
    }

    public function test_store_reports_a_class_conflict_for_a_different_subject_in_an_occupied_slot(): void
    {
        $user = $this->makeUser();
        $year = $this->makeYear();
        $class = $this->makeClass();
        $day = $this->makeDay();
        $period = $this->makePeriod();

        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $this->makeTeacher()->id, 'subject_id' => $this->makeSubject()->id,
        ]);

        $newSubject = $this->makeSubject();
        $newTeacher = $this->makeTeacher();
        $this->makeEligible($year, $class, $newSubject, $newTeacher);

        $response = $this->actingAs($user)->post(route('dashboard.timetable.store'), [
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'subject_id' => $newSubject->id, 'teacher_id' => $newTeacher->id,
        ]);

        $response->assertSessionHasErrors(['error' => __('timetable.class_conflict')]);
    }

    public function test_store_reports_a_teacher_conflict_across_different_classes(): void
    {
        $user = $this->makeUser();
        $year = $this->makeYear();
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $teacher = $this->makeTeacher();

        Timetable::create([
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $this->makeSubject()->id,
        ]);

        $newClass = $this->makeClass();
        $newSubject = $this->makeSubject();
        $this->makeEligible($year, $newClass, $newSubject, $teacher);

        $response = $this->actingAs($user)->post(route('dashboard.timetable.store'), [
            'class_id' => $newClass->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'subject_id' => $newSubject->id, 'teacher_id' => $teacher->id,
        ]);

        $response->assertSessionHasErrors(['error' => __('timetable.teacher_conflict')]);
    }

    public function test_store_reports_a_room_conflict(): void
    {
        $user = $this->makeUser();
        $year = $this->makeYear();
        $day = $this->makeDay();
        $period = $this->makePeriod();

        Timetable::create([
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $this->makeTeacher()->id, 'subject_id' => $this->makeSubject()->id,
            'room' => 'A101',
        ]);

        $newClass = $this->makeClass();
        $newSubject = $this->makeSubject();
        $newTeacher = $this->makeTeacher();
        $this->makeEligible($year, $newClass, $newSubject, $newTeacher);

        $response = $this->actingAs($user)->post(route('dashboard.timetable.store'), [
            'class_id' => $newClass->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'subject_id' => $newSubject->id, 'teacher_id' => $newTeacher->id,
            'room' => 'A101',
        ]);

        $response->assertSessionHasErrors(['error' => __('timetable.room_conflict')]);
    }

    public function test_store_rejects_a_subject_outside_the_class_curriculum(): void
    {
        $user = $this->makeUser();
        $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        // Deliberately no Curriculum row for $subject at all.

        $response = $this->actingAs($user)->post(route('dashboard.timetable.store'), [
            'class_id' => $class->id, 'day_id' => $this->makeDay()->id, 'period_id' => $this->makePeriod()->id,
            'subject_id' => $subject->id, 'teacher_id' => $this->makeTeacher()->id,
        ]);

        $response->assertSessionHasErrors(['error' => __('timetable.subject_not_in_curriculum')]);
        $this->assertSame(0, Timetable::count());
    }

    public function test_store_rejects_once_the_curriculum_weekly_hours_quota_is_met(): void
    {
        $user = $this->makeUser();
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $this->makeCurriculum($year, $class, $subject, weeklyHours: 1);
        $this->makeAssignment($year, $class, $subject, $teacher);

        $day = $this->makeDay();
        $periodOne = $this->makePeriod();
        $periodTwo = $this->makePeriod();

        // One lesson of $subject already scheduled — the quota (1) is met.
        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $periodOne->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $response = $this->actingAs($user)->post(route('dashboard.timetable.store'), [
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $periodTwo->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        ]);

        $response->assertSessionHasErrors(['error' => __('timetable.weekly_hours_exceeded')]);
        $this->assertSame(1, Timetable::where('class_id', $class->id)->where('subject_id', $subject->id)->count());
    }

    public function test_store_rejects_a_teacher_without_a_matching_teacher_assignment(): void
    {
        $user = $this->makeUser();
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $this->makeCurriculum($year, $class, $subject);
        // Deliberately no TeacherAssignment for $teacher at all.

        $response = $this->actingAs($user)->post(route('dashboard.timetable.store'), [
            'class_id' => $class->id, 'day_id' => $this->makeDay()->id, 'period_id' => $this->makePeriod()->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        ]);

        $response->assertSessionHasErrors(['error' => __('timetable.teacher_not_assigned')]);
        $this->assertSame(0, Timetable::count());
    }

    public function test_store_rejects_a_non_working_day(): void
    {
        $user = $this->makeUser();
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $this->makeEligible($year, $class, $subject, $teacher);

        $friday = Day::create(['name' => 'Friday', 'code' => 'fri', 'order' => 0]);

        $response = $this->actingAs($user)->post(route('dashboard.timetable.store'), [
            'class_id' => $class->id, 'day_id' => $friday->id, 'period_id' => $this->makePeriod()->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        ]);

        $response->assertSessionHasErrors(['error' => __('timetable.non_working_day')]);
        $this->assertSame(0, Timetable::count());
    }

    public function test_store_succeeds_when_nothing_conflicts(): void
    {
        $user = $this->makeUser();
        $year = $this->makeYear();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $this->makeEligible($year, $class, $subject, $teacher);

        $response = $this->actingAs($user)->post(route('dashboard.timetable.store'), [
            'class_id' => $class->id, 'day_id' => $this->makeDay()->id, 'period_id' => $this->makePeriod()->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(1, Timetable::count());
    }

    public function test_update_excludes_the_record_being_edited_from_every_rule(): void
    {
        $user = $this->makeUser();
        $year = $this->makeYear();
        $class = $this->makeClass();
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();
        $this->makeEligible($year, $class, $subject, $teacher);

        $timetable = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        // A no-op update (same slot, same everything) must not conflict
        // with itself.
        $response = $this->actingAs($user)->put(route('dashboard.timetable.update', $timetable), [
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        ]);

        $response->assertSessionDoesntHaveErrors();
    }

    public function test_move_rejects_a_teacher_conflict_at_the_target_slot(): void
    {
        $user = $this->makeUser();
        $year = $this->makeYear();
        $day = $this->makeDay();
        $targetPeriod = $this->makePeriod();
        $sourcePeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);
        $teacher = $this->makeTeacher();

        $movingClass = $this->makeClass();
        $movingSubject = $this->makeSubject();
        $this->makeEligible($year, $movingClass, $movingSubject, $teacher);

        $moving = Timetable::create([
            'class_id' => $movingClass->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacher->id, 'subject_id' => $movingSubject->id,
        ]);
        Timetable::create([
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $targetPeriod->id,
            'teacher_id' => $teacher->id, 'subject_id' => $this->makeSubject()->id,
        ]);

        $response = $this->actingAs($user)->post(route('dashboard.timetable.move', $moving), [
            'day_id' => $day->id, 'period_id' => $targetPeriod->id,
        ]);

        $response->assertStatus(422)->assertJson(['success' => false, 'message' => __('timetable.teacher_conflict')]);
        $this->assertSame($sourcePeriod->id, $moving->fresh()->period_id);
    }

    public function test_move_succeeds_when_nothing_conflicts(): void
    {
        $user = $this->makeUser();
        $year = $this->makeYear();
        $day = $this->makeDay();
        $sourcePeriod = $this->makePeriod();
        $targetPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $teacher = $this->makeTeacher();
        $this->makeEligible($year, $class, $subject, $teacher);

        $moving = Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $response = $this->actingAs($user)->post(route('dashboard.timetable.move', $moving), [
            'day_id' => $day->id, 'period_id' => $targetPeriod->id,
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame($targetPeriod->id, $moving->fresh()->period_id);
    }
}
