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
use App\Models\TimetableSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UAT fix: "Расписание" in the /dashboard sidebar previously left the
 * classic dashboard shell for /admin/classes (Filament). This covers the
 * replacement dashboard-native surface (dashboard.classes.timetable),
 * which reuses the same Timetable model and the same
 * TimetableLessonService / TimetableGenerationService the Filament
 * ClassResource\Pages\TimetableGrid delegates to — see
 * TimetableGridGenerateTest / TimetableGridManualEditTest for coverage of
 * the shared services themselves, unchanged by this batch.
 */
class DashboardClassTimetableTest extends TestCase
{
    use RefreshDatabase;

    protected function adminUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    /** An administrative-portal role that exists but is not granted timetable permissions. */
    protected function schoolAdminUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('school-admin');

        return $user;
    }

    protected function teacherOnlyUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('teacher');

        // canAccessPanel('teacher') additionally requires an active,
        // uniquely linked Teacher row (User::canAccessPanel()) — without
        // it EnsureAdministrativePortalAccess would log the user out
        // instead of redirecting to the Teacher Portal, which is a
        // different, unrelated pre-existing behavior this test isn't
        // about.
        Teacher::create([
            'user_id' => $user->id, 'first_name' => 'T', 'last_name' => 'Teacher-' . uniqid(), 'is_active' => true,
        ]);

        return $user;
    }

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Primary ' . uniqid()]);
        $grade = Grade::create(['name' => 'Grade ' . uniqid(), 'stage_id' => $stage->id]);

        return SchoolClass::create([
            'grade_id' => $grade->id, 'code' => 'C-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true,
        ]);
    }

    /** All 7 Day rows, sun..sat — matches DaySeeder's codes/order exactly. */
    protected function makeAllDays(): array
    {
        $days = [];
        foreach ([
            ['code' => 'sun', 'order' => 0, 'name' => 'Воскресенье'],
            ['code' => 'mon', 'order' => 1, 'name' => 'Понедельник'],
            ['code' => 'tue', 'order' => 2, 'name' => 'Вторник'],
            ['code' => 'wed', 'order' => 3, 'name' => 'Среда'],
            ['code' => 'thu', 'order' => 4, 'name' => 'Четверг'],
            ['code' => 'fri', 'order' => 5, 'name' => 'Пятница'],
            ['code' => 'sat', 'order' => 6, 'name' => 'Суббота'],
        ] as $day) {
            $days[$day['code']] = Day::create($day);
        }

        return $days;
    }

    public function test_sidebar_timetable_link_stays_inside_dashboard_not_admin(): void
    {
        $admin = $this->adminUser();

        $html = (string) $this->actingAs($admin)
            ->view('layouts.partials.shell-sidebar');

        $this->assertStringContainsString(route('dashboard.classes.index'), $html);
        $this->assertStringNotContainsString('/admin/classes', $html);
        $this->assertStringStartsWith(url('/dashboard'), route('dashboard.classes.index'));
    }

    public function test_authorized_administrative_user_can_open_the_dashboard_timetable_surface(): void
    {
        $admin = $this->adminUser();
        $class = $this->makeClass();

        $response = $this->actingAs($admin)->get(route('dashboard.classes.timetable', $class));

        $response->assertOk();
        $response->assertSee($class->name_ru);
    }

    public function test_timetable_page_renders_inside_the_classic_dashboard_shell(): void
    {
        $admin = $this->adminUser();
        $class = $this->makeClass();

        $response = $this->actingAs($admin)->get(route('dashboard.classes.timetable', $class));

        // layouts/dashboard.blade.php's shell wrapper + its sidebar include —
        // proves this is the same classic dashboard chrome as every other
        // /dashboard page, not a standalone or Filament-rendered page.
        $response->assertSee('data-shell', false);
        $response->assertSee(route('dashboard.index'), false);
    }

    public function test_direct_access_is_forbidden_for_an_administrative_user_without_timetable_permission(): void
    {
        $schoolAdmin = $this->schoolAdminUser();
        $class = $this->makeClass();

        $this->actingAs($schoolAdmin)
            ->get(route('dashboard.classes.timetable', $class))
            ->assertForbidden();
    }

    public function test_teacher_only_user_is_redirected_away_from_the_dashboard_not_shown_the_timetable(): void
    {
        $teacher = $this->teacherOnlyUser();
        $class = $this->makeClass();

        // EnsureAdministrativePortalAccess redirects a teacher-panel-only
        // user to the Teacher Portal before the route's own permission
        // check is ever reached — teachers stay isolated there, per the
        // existing, unmodified boundary this batch does not touch.
        $this->actingAs($teacher)
            ->get(route('dashboard.classes.timetable', $class))
            ->assertRedirect(route('filament.teacher.pages.dashboard'));
    }

    public function test_guest_cannot_access_the_dashboard_timetable_surface(): void
    {
        $class = $this->makeClass();

        $this->get(route('dashboard.classes.timetable', $class))
            ->assertRedirect(route('login'));
    }

    public function test_saving_a_lesson_through_the_dashboard_persists_to_the_canonical_timetable_table(): void
    {
        $admin = $this->adminUser();
        $class = $this->makeClass();
        $year = AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $subject = Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'A', 'last_name' => 'B-' . uniqid(), 'is_active' => true]);
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 5, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $response = $this->actingAs($admin)->post(route('dashboard.classes.timetable.save', $class), [
            'day_id' => $day->id,
            'period_id' => $period->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // No parallel model was introduced for this surface — it is the
        // exact same `timetables` table / App\Models\Timetable row the
        // Filament TimetableGrid reads and writes.
        $this->assertSame(1, Timetable::where('class_id', $class->id)
            ->where('day_id', $day->id)->where('period_id', $period->id)
            ->where('subject_id', $subject->id)->where('teacher_id', $teacher->id)
            ->count());
    }

    public function test_saving_a_conflicting_lesson_through_the_dashboard_is_rejected_by_the_shared_conflict_checker(): void
    {
        $admin = $this->adminUser();
        $year = AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $day = Day::create(['name' => 'd', 'code' => 'd1', 'order' => 0]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $teacher = Teacher::create(['first_name' => 'A', 'last_name' => 'B-' . uniqid(), 'is_active' => true]);
        $subject = Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);

        $busyClass = $this->makeClass();
        Timetable::create([
            'class_id' => $busyClass->id, 'day_id' => $day->id, 'period_id' => $period->id,
            'teacher_id' => $teacher->id, 'subject_id' => $subject->id,
        ]);

        $class = $this->makeClass();
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
            'weekly_hours' => 5, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $response = $this->actingAs($admin)->post(route('dashboard.classes.timetable.save', $class), [
            'day_id' => $day->id,
            'period_id' => $period->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(0, Timetable::where('class_id', $class->id)->count());
    }

    public function test_generate_action_is_forbidden_for_a_view_only_user(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        // 'school-admin' carries administrative-portal access but neither
        // timetable permission (seeder strips both) — 'view timetable' is
        // then granted directly, so this user can open the grid but must
        // still be rejected by generate()'s separate can('manage timetable')
        // check. Using role 'admin' here would be meaningless: its
        // permissions come from the role, not a per-user grant, so
        // revokePermissionTo() on the user would be a no-op.
        $user->assignRole('school-admin');
        $user->givePermissionTo('view timetable');

        $class = $this->makeClass();

        $this->actingAs($user)
            ->post(route('dashboard.classes.timetable.generate', $class))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Timetable UI cleanup — working-day columns
    |--------------------------------------------------------------------------
    */

    public function test_display_uses_the_configured_non_working_days_not_a_hard_coded_friday_saturday(): void
    {
        $admin = $this->adminUser();
        $class = $this->makeClass();
        $days = $this->makeAllDays();
        // Deliberately NOT the default fri/sat, so this only passes if the
        // grid genuinely reads TimetableSetting instead of assuming
        // Friday/Saturday.
        TimetableSetting::updateOrCreate(['id' => 1], ['non_working_days' => ['mon', 'tue']]);

        $response = $this->actingAs($admin)->get(route('dashboard.classes.timetable', $class));

        $response->assertOk();
        foreach (['Воскресенье', 'Среда', 'Четверг', 'Пятница', 'Суббота'] as $workingDayName) {
            $response->assertSee($workingDayName);
        }
        foreach (['Понедельник', 'Вторник'] as $nonWorkingDayName) {
            $response->assertDontSee($nonWorkingDayName);
        }
    }

    public function test_display_hides_friday_and_saturday_under_the_default_configuration(): void
    {
        $admin = $this->adminUser();
        $class = $this->makeClass();
        $this->makeAllDays();
        TimetableSetting::updateOrCreate(['id' => 1], ['non_working_days' => ['fri', 'sat']]);

        $response = $this->actingAs($admin)->get(route('dashboard.classes.timetable', $class));

        $response->assertOk();
        foreach (['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг'] as $workingDayName) {
            $response->assertSee($workingDayName);
        }
        foreach (['Пятница', 'Суббота'] as $nonWorkingDayName) {
            $response->assertDontSee($nonWorkingDayName);
        }
    }

    public function test_a_non_working_day_change_is_reflected_without_any_code_change(): void
    {
        $admin = $this->adminUser();
        $class = $this->makeClass();
        $this->makeAllDays();

        TimetableSetting::updateOrCreate(['id' => 1], ['non_working_days' => ['fri', 'sat']]);
        $before = $this->actingAs($admin)->get(route('dashboard.classes.timetable', $class));
        $before->assertDontSee('Пятница');

        // Reconfiguring the school's weekly days off (as an admin would via
        // the Academic Calendar / school settings workflow) changes what
        // the grid shows on the very next request — proving the display
        // follows configuration rather than a fixed assumption.
        TimetableSetting::updateOrCreate(['id' => 1], ['non_working_days' => ['sun']]);
        $after = $this->actingAs($admin)->get(route('dashboard.classes.timetable', $class));
        $after->assertSee('Пятница');
        $after->assertDontSee('Воскресенье');
    }

    /*
    |--------------------------------------------------------------------------
    | Timetable UI cleanup — compact lesson display
    |--------------------------------------------------------------------------
    */

    public function test_an_existing_lesson_renders_as_a_compact_card_with_subject_and_teacher(): void
    {
        $admin = $this->adminUser();
        $class = $this->makeClass();
        $days = $this->makeAllDays();
        TimetableSetting::updateOrCreate(['id' => 1], ['non_working_days' => ['fri', 'sat']]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $subject = Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'Русский язык', 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'Иван', 'last_name' => 'Иванов', 'is_active' => true]);

        Timetable::create([
            'class_id' => $class->id, 'day_id' => $days['sun']->id, 'period_id' => $period->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard.classes.timetable', $class));

        $response->assertOk();
        $response->assertSee('lesson-card', false);
        $response->assertSee('Русский язык');
        $response->assertSee($teacher->full_name);
    }

    public function test_an_empty_slot_renders_a_compact_add_lesson_action_not_a_permanent_form(): void
    {
        $admin = $this->adminUser();
        $class = $this->makeClass();
        $this->makeAllDays();
        TimetableSetting::updateOrCreate(['id' => 1], ['non_working_days' => ['fri', 'sat']]);
        Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);

        $response = $this->actingAs($admin)->get(route('dashboard.classes.timetable', $class));

        $response->assertOk();
        $response->assertSee(__('timetable.add_lesson'));
        // The select/save controls still exist in the markup (for the
        // collapse-to-edit interaction) but must not be visible by default.
        $response->assertSee('class="collapse timetable-editor"', false);
    }

    public function test_updating_an_existing_lesson_through_the_dashboard_still_works_after_the_ui_refactor(): void
    {
        $admin = $this->adminUser();
        $class = $this->makeClass();
        $days = $this->makeAllDays();
        $year = AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $originalSubject = Subject::create(['code' => 'S1-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);
        $newSubject = Subject::create(['code' => 'S2-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'b', 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'A', 'last_name' => 'B-' . uniqid(), 'is_active' => true]);

        foreach ([$originalSubject, $newSubject] as $subject) {
            Curriculum::create([
                'academic_year_id' => $year->id, 'grade_id' => $class->grade_id, 'subject_id' => $subject->id,
                'weekly_hours' => 5, 'type' => Curriculum::TYPE_MANDATORY,
            ]);
            TeacherAssignment::create([
                'teacher_id' => $teacher->id, 'class_id' => $class->id,
                'subject_id' => $subject->id, 'academic_year_id' => $year->id,
            ]);
        }

        $lesson = Timetable::create([
            'class_id' => $class->id, 'day_id' => $days['sun']->id, 'period_id' => $period->id,
            'subject_id' => $originalSubject->id, 'teacher_id' => $teacher->id,
        ]);

        $response = $this->actingAs($admin)->post(route('dashboard.classes.timetable.save', $class), [
            'day_id' => $days['sun']->id,
            'period_id' => $period->id,
            'subject_id' => $newSubject->id,
            'teacher_id' => $teacher->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame($newSubject->id, $lesson->fresh()->subject_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Dashboard-only routing
    |--------------------------------------------------------------------------
    */

    public function test_timetable_workflow_routes_never_resolve_to_admin(): void
    {
        $class = $this->makeClass();

        foreach ([
            route('dashboard.classes.timetable', $class),
            route('dashboard.classes.timetable.save', $class),
            route('dashboard.classes.timetable.generate', $class),
        ] as $url) {
            $this->assertStringStartsWith(url('/dashboard'), $url);
            $this->assertStringNotContainsString('/admin', $url);
        }
    }
}
