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
use Barryvdh\DomPDF\Facade\Pdf;
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
        // click-to-edit interaction, toggled by inline JS rather than
        // Bootstrap's collapse component) but must not be visible by
        // default — hidden via the timetable-editor CSS class, not the
        // "collapse" class, so the toggle never depends on Bootstrap's JS
        // bundle loading successfully from its CDN.
        $response->assertSee('class="timetable-editor"', false);
        // Bootstrap's collapse component (data-bs-toggle="collapse") is no
        // longer used to open/close the editor — the toggle must not depend
        // on Bootstrap's externally-loaded JS bundle at all.
        $response->assertDontSee('data-bs-toggle="collapse"', false);
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
    | Manual delete — goes through TimetableLessonService like save()
    |--------------------------------------------------------------------------
    */

    public function test_deleting_a_lesson_through_the_dashboard_removes_it_via_the_service_layer(): void
    {
        $admin = $this->adminUser();
        $class = $this->makeClass();
        $days = $this->makeAllDays();
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $subject = Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'A', 'last_name' => 'B-' . uniqid(), 'is_active' => true]);

        Timetable::create([
            'class_id' => $class->id, 'day_id' => $days['sun']->id, 'period_id' => $period->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        ]);

        $response = $this->actingAs($admin)->delete(route('dashboard.classes.timetable.destroy', $class), [
            'day_id' => $days['sun']->id,
            'period_id' => $period->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(0, Timetable::where('class_id', $class->id)
            ->where('day_id', $days['sun']->id)->where('period_id', $period->id)->count());
    }

    public function test_deleting_an_empty_slot_is_a_safe_no_op(): void
    {
        $admin = $this->adminUser();
        $class = $this->makeClass();
        $days = $this->makeAllDays();
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);

        $response = $this->actingAs($admin)->delete(route('dashboard.classes.timetable.destroy', $class), [
            'day_id' => $days['sun']->id,
            'period_id' => $period->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(0, Timetable::where('class_id', $class->id)->count());
    }

    public function test_delete_action_is_forbidden_for_a_view_only_user(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('school-admin');
        $user->givePermissionTo('view timetable');

        $class = $this->makeClass();
        $days = $this->makeAllDays();
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);

        $this->actingAs($user)
            ->delete(route('dashboard.classes.timetable.destroy', $class), [
                'day_id' => $days['sun']->id,
                'period_id' => $period->id,
            ])
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | PDF preview / download / print — share the same working-day-filtered
    | grid data as the interactive surface (App\Http\Controllers\Dashboard\
    | ClassTimetableController::gridData()).
    |--------------------------------------------------------------------------
    */

    public function test_pdf_preview_route_returns_a_pdf_for_an_authorized_user(): void
    {
        $admin = $this->adminUser();
        $class = $this->makeClass();
        $this->makeAllDays();
        TimetableSetting::updateOrCreate(['id' => 1], ['non_working_days' => ['fri', 'sat']]);
        Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);

        $response = $this->actingAs($admin)->get(route('dashboard.classes.timetable.pdf', $class));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_empty_standard_week_pdf_renders_on_one_landscape_page(): void
    {
        $class = $this->makeClass();
        $class->forceFill(['name_ru' => '1 КЛАСС'])->save();
        $days = collect($this->makeAllDays())->only(['sun', 'mon', 'tue', 'wed', 'thu'])->values();
        $periods = collect(range(1, 6))->map(fn (int $number) => Period::create([
            'number' => $number,
            'start_time' => sprintf('%02d:00', $number + 7),
            'end_time' => sprintf('%02d:45', $number + 7),
        ]));

        $pdf = Pdf::loadView('dashboard.classes.timetable-pdf', [
            'class' => $class,
            'days' => $days,
            'periods' => $periods,
            'lessons' => collect(),
            'academicYear' => null,
            'schoolSettings' => null,
        ])->setPaper('a4', 'landscape');

        $pdf->output();

        $this->assertSame(1, $pdf->getDomPDF()->getCanvas()->get_page_count());
    }

    public function test_populated_standard_week_with_long_russian_names_renders_on_one_page(): void
    {
        $class = $this->makeClass();
        $class->forceFill(['name_ru' => '4 КЛАСС'])->save();
        $days = collect($this->makeAllDays())->only(['sun', 'mon', 'tue', 'wed', 'thu'])->values();
        $periods = collect(range(1, 6))->map(fn (int $number) => Period::create([
            'number' => $number,
            'start_time' => sprintf('%02d:00', $number + 7),
            'end_time' => sprintf('%02d:45', $number + 7),
        ]));
        $subject = Subject::create([
            'code' => 'LONG-RU',
            'name_ar' => 'a',
            'name_ru' => 'Литературное чтение и развитие речи',
            'is_active' => true,
        ]);
        $teacher = Teacher::create([
            'first_name' => 'Александра-Мария',
            'last_name' => 'Константинопольская-Смирнова',
            'is_active' => true,
        ]);

        foreach ($periods as $period) {
            foreach ($days as $day) {
                Timetable::create([
                    'class_id' => $class->id,
                    'day_id' => $day->id,
                    'period_id' => $period->id,
                    'subject_id' => $subject->id,
                    'teacher_id' => $teacher->id,
                ]);
            }
        }

        $lessons = Timetable::with(['subject', 'teacher'])
            ->where('class_id', $class->id)
            ->get()
            ->keyBy(fn (Timetable $lesson) => $lesson->day_id.'-'.$lesson->period_id);
        $viewData = [
            'class' => $class,
            'days' => $days,
            'periods' => $periods,
            'lessons' => $lessons,
            'academicYear' => null,
            'schoolSettings' => null,
        ];
        $html = view('dashboard.classes.timetable-pdf', $viewData)->render();
        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape');

        $pdf->output();

        $this->assertSame(1, $pdf->getDomPDF()->getCanvas()->get_page_count());
        $this->assertStringContainsString('table-layout: fixed', $html);
        $this->assertStringContainsString('page-break-inside: avoid', $html);
        $this->assertStringContainsString('Литературное чтение и развитие речи', $html);
        $this->assertStringContainsString('Константинопольская-Смирнова', $html);
        $this->assertSame(6, substr_count($html, '<tr>') - 2); // Excludes document header and grid header rows.
        $this->assertSame(30, substr_count($html, '<span class="subject">'));
    }

    public function test_pdf_download_route_uses_a_safe_transliterated_filename_not_the_raw_database_id(): void
    {
        $admin = $this->adminUser();
        $class = $this->makeClass();
        $class->forceFill(['name_ru' => 'Выпускной класс'])->save();
        $this->makeAllDays();
        TimetableSetting::updateOrCreate(['id' => 1], ['non_working_days' => ['fri', 'sat']]);
        Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);

        $response = $this->actingAs($admin)->get(route('dashboard.classes.timetable.pdf.download', $class));

        $response->assertOk();
        $disposition = $response->headers->get('content-disposition');
        // Transliterated from the class name, not "{$class->id}.pdf" — the
        // bare numeric primary key never appears as the filename itself.
        $this->assertStringContainsString('raspisanie-vypusknoi-klass.pdf', $disposition);
        $this->assertStringNotContainsString('filename=' . $class->id . '.pdf', $disposition);
    }

    public function test_print_route_renders_the_schedule_without_dashboard_navigation_chrome(): void
    {
        $admin = $this->adminUser();
        $class = $this->makeClass();
        $days = $this->makeAllDays();
        TimetableSetting::updateOrCreate(['id' => 1], ['non_working_days' => ['fri', 'sat']]);
        $period = Period::create(['number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']);
        $subject = Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'Русский язык', 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'A', 'last_name' => 'B-' . uniqid(), 'is_active' => true]);

        Timetable::create([
            'class_id' => $class->id, 'day_id' => $days['sun']->id, 'period_id' => $period->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard.classes.timetable.print', $class));

        $response->assertOk();
        $response->assertSee('Русский язык');
        // Not wrapped in the dashboard shell: no sidebar, no add/save/delete/generate controls.
        $response->assertDontSee(__('timetable.add_lesson'));
        $response->assertDontSee(__('timetable.generate_smart_timetable'));
        $response->assertDontSee(__('timetable.save'));
        $response->assertDontSee(__('timetable.delete_lesson'));
    }

    public function test_pdf_and_print_routes_follow_the_same_view_timetable_permission_as_the_interactive_grid(): void
    {
        $schoolAdmin = $this->schoolAdminUser();
        $class = $this->makeClass();

        foreach ([
            route('dashboard.classes.timetable.pdf', $class),
            route('dashboard.classes.timetable.pdf.download', $class),
            route('dashboard.classes.timetable.print', $class),
        ] as $url) {
            $this->actingAs($schoolAdmin)->get($url)->assertForbidden();
        }
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
            route('dashboard.classes.timetable.destroy', $class),
            route('dashboard.classes.timetable.generate', $class),
            route('dashboard.classes.timetable.pdf', $class),
            route('dashboard.classes.timetable.pdf.download', $class),
            route('dashboard.classes.timetable.print', $class),
        ] as $url) {
            $this->assertStringStartsWith(url('/dashboard'), $url);
            $this->assertStringNotContainsString('/admin', $url);
        }
    }
}
