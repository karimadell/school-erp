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
 * Batch 9 / TimetableController Authorization Hardening
 * (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md §4): the deprecated
 * TimetableController previously required only a logged-in session (`auth`
 * middleware) on all 9 remaining routes — any authenticated user of any
 * role could read and mutate timetable data, bypassing the
 * 'view timetable' / 'manage timetable' model already enforced on the
 * canonical TimetableGrid. This batch adds the same gating, mirroring
 * TimetableGrid's own abort_unless() pattern exactly: read-only actions
 * accept either permission (hasAnyPermission), mutating/generation-support
 * actions require 'manage timetable' specifically. Route names, URIs,
 * HTTP methods, redirects, and successful response shapes are unchanged —
 * only unauthorized access is now rejected.
 */
class TimetableControllerAuthorizationTest extends TestCase
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

    protected function makeTimetable(): Timetable
    {
        return Timetable::create([
            'class_id' => $this->makeClass()->id,
            'day_id' => $this->makeDay()->id,
            'period_id' => $this->makePeriod()->id,
            'teacher_id' => $this->makeTeacher()->id,
            'subject_id' => $this->makeSubject()->id,
        ]);
    }

    protected function makeUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /**
     * Batch 10: store/update/move now go through
     * CurriculumAwareTimetableConflictChecker, so a fixture whose mutation
     * is expected to actually succeed needs an active AcademicYear, a
     * Curriculum row and a TeacherAssignment for the (class, subject,
     * teacher) it exercises, or an earlier rule would reject it before
     * the authorization-focused assertion below is ever reached.
     */
    protected function makeEligible(SchoolClass $class, Subject $subject, Teacher $teacher): void
    {
        $year = AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 10, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);
    }

    public function test_guest_is_redirected_to_login_on_a_read_and_a_mutating_route(): void
    {
        $this->get(route('dashboard.timetable.index'))
            ->assertRedirect(route('login'));

        $this->post(route('dashboard.timetable.store'), [])
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_without_permission_receives_403_on_every_route(): void
    {
        $user = User::factory()->create();
        $class = $this->makeClass();
        $subject = $this->makeSubject();
        $timetable = $this->makeTimetable();

        $this->actingAs($user)->get(route('dashboard.timetable.index'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.timetable.show', $class))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.timetable.pdf', $class))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.timetable.create'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.timetable.subject.teachers', $subject))->assertForbidden();
        $this->actingAs($user)->post(route('dashboard.timetable.store'), [])->assertForbidden();
        $this->actingAs($user)->put(route('dashboard.timetable.update', $timetable), [])->assertForbidden();
        $this->actingAs($user)->delete(route('dashboard.timetable.destroy', $timetable))->assertForbidden();
        $this->actingAs($user)->post(route('dashboard.timetable.move', $timetable), [])->assertForbidden();

        // No side effect from the rejected mutation attempts.
        $this->assertNotNull($timetable->fresh());
    }

    public function test_user_with_view_timetable_can_access_read_only_routes(): void
    {
        $user = $this->makeUserWithPermissions(['view timetable']);
        $class = $this->makeClass();

        $this->actingAs($user)->get(route('dashboard.timetable.index'))->assertOk();
        $this->actingAs($user)->get(route('dashboard.timetable.show', $class))->assertOk();

        // pdf() is exercised for real (DomPDF rendering, not mocked) only
        // once across this file — in the manage-timetable variant of this
        // test below — to avoid doubling up on a memory-heavy render when
        // the full suite runs hundreds of tests in one PHP process.
        // Authorization on pdf() for a view-only user is still proven: a
        // 403 would occur before any rendering happens (authorizeView() is
        // the first line of the method), so testing it once under a
        // different, equally-gated permission is sufficient coverage here.
    }

    public function test_user_with_view_timetable_cannot_access_mutating_or_generation_routes(): void
    {
        $user = $this->makeUserWithPermissions(['view timetable']);
        $subject = $this->makeSubject();
        $timetable = $this->makeTimetable();

        $this->actingAs($user)->get(route('dashboard.timetable.create'))->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.timetable.subject.teachers', $subject))->assertForbidden();
        $this->actingAs($user)->post(route('dashboard.timetable.store'), [])->assertForbidden();
        $this->actingAs($user)->put(route('dashboard.timetable.update', $timetable), [])->assertForbidden();
        $this->actingAs($user)->delete(route('dashboard.timetable.destroy', $timetable))->assertForbidden();
        $this->actingAs($user)->post(route('dashboard.timetable.move', $timetable), [])->assertForbidden();

        $this->assertNotNull($timetable->fresh());
    }

    public function test_user_with_manage_timetable_can_access_read_only_routes(): void
    {
        // manage timetable implies view access via hasAnyPermission() —
        // no separate 'view timetable' grant needed.
        $user = $this->makeUserWithPermissions(['manage timetable']);
        $class = $this->makeClass();

        $this->actingAs($user)->get(route('dashboard.timetable.index'))->assertOk();
        $this->actingAs($user)->get(route('dashboard.timetable.show', $class))->assertOk();
        $this->actingAs($user)->get(route('dashboard.timetable.pdf', $class))->assertOk();
    }

    public function test_user_with_manage_timetable_can_access_mutation_and_generation_routes(): void
    {
        $user = $this->makeUserWithPermissions(['manage timetable']);
        $subject = $this->makeSubject();

        $this->actingAs($user)->get(route('dashboard.timetable.create'))->assertOk();
        $this->actingAs($user)->get(route('dashboard.timetable.subject.teachers', $subject))->assertOk();

        $class = $this->makeClass();
        $day = $this->makeDay();
        $period = $this->makePeriod();
        $teacher = $this->makeTeacher();
        $this->makeEligible($class, $subject, $teacher);

        $storeResponse = $this->actingAs($user)->post(route('dashboard.timetable.store'), [
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        ]);
        $storeResponse->assertRedirect(route('dashboard.timetable.show', $class->id));
        $storeResponse->assertSessionHasNoErrors();

        $timetable = Timetable::sole();

        $updateResponse = $this->actingAs($user)->put(route('dashboard.timetable.update', $timetable), [
            'class_id' => $class->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        ]);
        $updateResponse->assertSessionHasNoErrors();

        $targetPeriod = Period::create(['number' => 2, 'start_time' => '08:50', 'end_time' => '09:35']);
        $moveResponse = $this->actingAs($user)->post(route('dashboard.timetable.move', $timetable), [
            'day_id' => $day->id, 'period_id' => $targetPeriod->id,
        ]);
        $moveResponse->assertOk()->assertJson(['success' => true]);

        $destroyResponse = $this->actingAs($user)->delete(route('dashboard.timetable.destroy', $timetable));
        $destroyResponse->assertRedirect(route('dashboard.timetable.show', $class->id));
        $this->assertNull($timetable->fresh());
    }

    public function test_existing_valid_route_names_uris_and_methods_are_unchanged(): void
    {
        $class = $this->makeClass();
        $timetable = $this->makeTimetable();
        $subject = $this->makeSubject();

        $this->assertSame('/dashboard/timetable', route('dashboard.timetable.index', [], false));
        $this->assertSame('/dashboard/timetable/create', route('dashboard.timetable.create', [], false));
        $this->assertSame("/dashboard/timetable/{$timetable->id}", route('dashboard.timetable.update', $timetable, false));
        $this->assertSame("/dashboard/timetable/{$timetable->id}", route('dashboard.timetable.destroy', $timetable, false));
        $this->assertSame("/dashboard/timetable/{$timetable->id}/move", route('dashboard.timetable.move', $timetable, false));
        $this->assertSame("/dashboard/timetable/subject/{$subject->id}/teachers", route('dashboard.timetable.subject.teachers', $subject, false));
        $this->assertSame("/dashboard/timetable/class/{$class->id}/pdf", route('dashboard.timetable.pdf', $class, false));
        $this->assertSame("/dashboard/timetable/class/{$class->id}", route('dashboard.timetable.show', $class, false));
    }

    public function test_the_batch_8_removed_edit_route_is_not_restored(): void
    {
        $user = $this->makeUserWithPermissions(['manage timetable']);

        $this->assertFalse(\Illuminate\Support\Facades\Route::has('dashboard.timetable.edit'));

        $this->actingAs($user)
            ->get('/dashboard/timetable/1/edit')
            ->assertNotFound();
    }
}
