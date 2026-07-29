<?php

namespace Tests\Feature;

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
use Tests\TestCase;

/**
 * TimetableController now orchestrates App\Services\TimetableConflictChecker
 * instead of running its own conflict SQL — these tests exercise the real
 * store/update/move routes to prove the wiring, not just the service in
 * isolation (covered separately in TimetableConflictRulesTest /
 * TimetableConflictCheckerTest). No coverage existed for these routes
 * before this batch.
 */
class TimetableControllerConflictTest extends TestCase
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

    public function test_store_rejects_a_duplicate_lesson_before_the_broader_class_conflict(): void
    {
        $user = User::factory()->create();
        $class = $this->makeClass();
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();

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
        $user = User::factory()->create();
        $class = $this->makeClass();
        $day = $this->makeDay();
        $period = $this->makePeriod();

        Timetable::create([
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $this->makeTeacher()->id, 'subject_id' => $this->makeSubject()->id,
        ]);

        $response = $this->actingAs($user)->post(route('dashboard.timetable.store'), [
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'subject_id' => $this->makeSubject()->id, 'teacher_id' => $this->makeTeacher()->id,
        ]);

        $response->assertSessionHasErrors(['error' => __('timetable.class_conflict')]);
    }

    public function test_store_reports_a_teacher_conflict_across_different_classes(): void
    {
        $user = User::factory()->create();
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $teacher = $this->makeTeacher();

        Timetable::create([
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $this->makeSubject()->id,
        ]);

        $response = $this->actingAs($user)->post(route('dashboard.timetable.store'), [
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'subject_id' => $this->makeSubject()->id, 'teacher_id' => $teacher->id,
        ]);

        $response->assertSessionHasErrors(['error' => __('timetable.teacher_conflict')]);
    }

    public function test_store_reports_a_room_conflict(): void
    {
        $user = User::factory()->create();
        $day = $this->makeDay();
        $period = $this->makePeriod();

        Timetable::create([
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $this->makeTeacher()->id, 'subject_id' => $this->makeSubject()->id,
            'room' => 'A101',
        ]);

        $response = $this->actingAs($user)->post(route('dashboard.timetable.store'), [
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'subject_id' => $this->makeSubject()->id, 'teacher_id' => $this->makeTeacher()->id,
            'room' => 'A101',
        ]);

        $response->assertSessionHasErrors(['error' => __('timetable.room_conflict')]);
    }

    public function test_store_succeeds_when_nothing_conflicts(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('dashboard.timetable.store'), [
            'class_id' => $this->makeClass()->id, 'day_id' => $this->makeDay()->id, 'period_id' => $this->makePeriod()->id,
            'subject_id' => $this->makeSubject()->id, 'teacher_id' => $this->makeTeacher()->id,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(1, Timetable::count());
    }

    public function test_update_excludes_the_record_being_edited_from_every_rule(): void
    {
        $user = User::factory()->create();
        $class = $this->makeClass();
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $teacher = $this->makeTeacher();
        $subject = $this->makeSubject();

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
        $user = User::factory()->create();
        $day = $this->makeDay();
        $targetPeriod = $this->makePeriod();
        $sourcePeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);
        $teacher = $this->makeTeacher();

        $moving = Timetable::create([
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $teacher->id, 'subject_id' => $this->makeSubject()->id,
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
        $user = User::factory()->create();
        $day = $this->makeDay();
        $sourcePeriod = $this->makePeriod();
        $targetPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);

        $moving = Timetable::create([
            'class_id' => $this->makeClass()->id, 'day_id' => $day->id, 'period_id' => $sourcePeriod->id,
            'teacher_id' => $this->makeTeacher()->id, 'subject_id' => $this->makeSubject()->id,
        ]);

        $response = $this->actingAs($user)->post(route('dashboard.timetable.move', $moving), [
            'day_id' => $day->id, 'period_id' => $targetPeriod->id,
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame($targetPeriod->id, $moving->fresh()->period_id);
    }
}
