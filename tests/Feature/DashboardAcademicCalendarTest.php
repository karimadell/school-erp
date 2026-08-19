<?php

namespace Tests\Feature;

use App\Models\AcademicCalendar;
use App\Models\AcademicYear;
use App\Models\BellSchedule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 dashboard migration: dashboard-native counterpart to
 * App\Filament\Resources\AcademicCalendars\AcademicCalendarResource.
 * Reuses the exact same AcademicCalendar / CalendarEvent models and their
 * validation — no business logic is duplicated here.
 */
class DashboardAcademicCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function adminUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    protected function schoolAdminUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('school-admin');

        return $user;
    }

    protected function activeYear(): AcademicYear
    {
        return AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
    }

    /** Holds 'view timetable' only — never 'manage timetable'. */
    protected function viewOnlyUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('school-admin');
        $user->givePermissionTo('view timetable');

        return $user;
    }

    protected function calendar(AcademicYear $year, array $overrides = []): AcademicCalendar
    {
        return AcademicCalendar::create(array_merge([
            'academic_year_id' => $year->id, 'weekly_days_off' => ['fri', 'sat'],
        ], $overrides));
    }

    public function test_sidebar_academic_calendar_link_stays_inside_dashboard_not_admin(): void
    {
        $admin = $this->adminUser();

        $html = (string) $this->actingAs($admin)->view('layouts.partials.shell-sidebar');

        $this->assertStringContainsString(route('dashboard.academic-calendars.index'), $html);
        $this->assertStringNotContainsString('/admin/academic-calendars', $html);
    }

    public function test_authorized_administrative_user_can_view_the_list(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->get(route('dashboard.academic-calendars.index'))->assertOk();
    }

    public function test_direct_access_is_forbidden_without_timetable_permission(): void
    {
        $schoolAdmin = $this->schoolAdminUser();

        $this->actingAs($schoolAdmin)->get(route('dashboard.academic-calendars.index'))->assertForbidden();
    }

    public function test_guest_cannot_access_academic_calendars(): void
    {
        $this->get(route('dashboard.academic-calendars.index'))->assertRedirect(route('login'));
    }

    public function test_admin_can_create_a_calendar_for_the_active_year(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();

        $response = $this->actingAs($admin)->post(route('dashboard.academic-calendars.store'), [
            'academic_year_id' => $year->id,
            'weekly_days_off' => ['fri', 'sat'],
        ]);

        $response->assertRedirect(route('dashboard.academic-calendars.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('academic_calendars', ['academic_year_id' => $year->id]);
    }

    public function test_calendar_for_an_inactive_year_is_rejected_by_the_shared_model_validation(): void
    {
        $admin = $this->adminUser();
        $inactiveYear = AcademicYear::create([
            'name' => 'Old ' . uniqid(), 'start_date' => '2020-09-01', 'end_date' => '2021-05-31', 'is_active' => false,
        ]);

        // Bypass the create-form's active-year-only <select> to exercise the
        // model's own guard directly, the same way Filament's form would be
        // bypassed by a tampered request.
        $response = $this->actingAs($admin)->post(route('dashboard.academic-calendars.store'), [
            'academic_year_id' => $inactiveYear->id,
            'weekly_days_off' => ['fri', 'sat'],
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseMissing('academic_calendars', ['academic_year_id' => $inactiveYear->id]);
    }

    public function test_admin_can_add_an_event_to_an_existing_calendar(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $calendar = AcademicCalendar::create([
            'academic_year_id' => $year->id, 'weekly_days_off' => ['fri', 'sat'],
        ]);

        $response = $this->actingAs($admin)->post(route('dashboard.academic-calendars.events.store', $calendar), [
            'name' => 'Праздник',
            'type' => 'official_holiday',
            'start_date' => '2026-11-04',
            'end_date' => '2026-11-04',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('dashboard.academic-calendars.edit', $calendar));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('calendar_events', [
            'academic_calendar_id' => $calendar->id,
            'name' => 'Праздник',
            'type' => 'official_holiday',
        ]);
    }

    public function test_edit_page_never_leaves_the_dashboard_shell(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $calendar = AcademicCalendar::create([
            'academic_year_id' => $year->id, 'weekly_days_off' => ['fri', 'sat'],
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard.academic-calendars.edit', $calendar));

        $response->assertOk();
        $response->assertSee('data-shell', false);
        $response->assertDontSee('/admin/academic-calendars', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-UAT Fix 1 — one Academic Calendar per Academic Year
    |--------------------------------------------------------------------------
    */

    public function test_creating_a_second_calendar_for_the_same_year_is_a_normal_validation_error_not_a_500(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $this->calendar($year);

        $response = $this->actingAs($admin)->post(route('dashboard.academic-calendars.store'), [
            'academic_year_id' => $year->id,
            'weekly_days_off' => ['fri', 'sat'],
        ]);

        $response->assertSessionHasErrors('academic_year_id');
        $this->assertSame(1, AcademicCalendar::where('academic_year_id', $year->id)->count());
    }

    public function test_duplicate_create_validation_message_is_translated(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $this->calendar($year);

        $response = $this->actingAs($admin)->from(route('dashboard.academic-calendars.create'))->post(route('dashboard.academic-calendars.store'), [
            'academic_year_id' => $year->id,
            'weekly_days_off' => ['fri', 'sat'],
        ]);

        $response->assertSessionHasErrors([
            'academic_year_id' => __('academic_calendar.validation.academic_year_taken'),
        ]);
    }

    public function test_updating_a_calendar_to_a_year_already_taken_by_another_calendar_is_rejected(): void
    {
        $admin = $this->adminUser();
        // AcademicCalendar::booted() only allows creation for the currently
        // active year, so each calendar below is created while its own
        // year is briefly the active one — AcademicYear::save() atomically
        // deactivates the previous active year when a new one activates,
        // exactly like flipping the school's active year in production.
        $yearA = $this->activeYear();
        $this->calendar($yearA);
        $yearB = AcademicYear::create([
            'name' => 'YearB ' . uniqid(), 'start_date' => '2020-09-01', 'end_date' => '2021-05-31', 'is_active' => true,
        ]);
        $calendarB = $this->calendar($yearB);

        $response = $this->actingAs($admin)->put(route('dashboard.academic-calendars.update', $calendarB), [
            'academic_year_id' => $yearA->id,
            'weekly_days_off' => ['fri', 'sat'],
        ]);

        $response->assertSessionHasErrors('academic_year_id');
        $this->assertSame($yearB->id, $calendarB->fresh()->academic_year_id);
    }

    public function test_updating_a_calendar_while_keeping_its_own_academic_year_is_allowed(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $calendar = $this->calendar($year);

        $response = $this->actingAs($admin)->put(route('dashboard.academic-calendars.update', $calendar), [
            'academic_year_id' => $year->id,
            'weekly_days_off' => ['fri'],
        ]);

        $response->assertRedirect(route('dashboard.academic-calendars.edit', $calendar));
        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(['fri'], $calendar->fresh()->weekly_days_off);
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-UAT Fix 2 — read-only Edit action visibility
    |--------------------------------------------------------------------------
    */

    public function test_view_only_user_does_not_see_edit_action_on_the_list(): void
    {
        $user = $this->viewOnlyUser();
        $year = $this->activeYear();
        $calendar = $this->calendar($year);

        $html = (string) $this->actingAs($user)->get(route('dashboard.academic-calendars.index'))->getContent();

        $this->assertStringNotContainsString(route('dashboard.academic-calendars.edit', $calendar), $html);
    }

    public function test_manage_user_does_see_edit_action_on_the_list(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $calendar = $this->calendar($year);

        $html = (string) $this->actingAs($admin)->get(route('dashboard.academic-calendars.index'))->getContent();

        $this->assertStringContainsString(route('dashboard.academic-calendars.edit', $calendar), $html);
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-UAT Fix 4 — nested event ownership regression
    |--------------------------------------------------------------------------
    */

    public function test_editing_an_event_through_a_calendar_it_does_not_belong_to_is_not_found(): void
    {
        $admin = $this->adminUser();
        // See test_updating_a_calendar_to_a_year_already_taken... above for
        // why each calendar's year is briefly made active to create it.
        $yearA = $this->activeYear();
        $calendarA = $this->calendar($yearA);
        $yearB = AcademicYear::create([
            'name' => 'YearB ' . uniqid(), 'start_date' => '2020-09-01', 'end_date' => '2021-05-31', 'is_active' => true,
        ]);
        $calendarB = $this->calendar($yearB);
        $eventB = $calendarB->events()->create([
            'name' => 'Event B', 'type' => 'official_holiday',
            'start_date' => '2020-10-01', 'end_date' => '2020-10-01', 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard.academic-calendars.events.edit', [$calendarA, $eventB]))
            ->assertNotFound();
    }

    public function test_updating_an_event_through_a_calendar_it_does_not_belong_to_is_not_found(): void
    {
        $admin = $this->adminUser();
        $yearA = $this->activeYear();
        $calendarA = $this->calendar($yearA);
        $yearB = AcademicYear::create([
            'name' => 'YearB ' . uniqid(), 'start_date' => '2020-09-01', 'end_date' => '2021-05-31', 'is_active' => true,
        ]);
        $calendarB = $this->calendar($yearB);
        $eventB = $calendarB->events()->create([
            'name' => 'Event B', 'type' => 'official_holiday',
            'start_date' => '2020-10-01', 'end_date' => '2020-10-01', 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('dashboard.academic-calendars.events.update', [$calendarA, $eventB]), [
                'name' => 'Tampered', 'type' => 'official_holiday',
                'start_date' => '2020-10-01', 'end_date' => '2020-10-01', 'is_active' => '1',
            ])
            ->assertNotFound();

        $this->assertSame('Event B', $eventB->fresh()->name);
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-UAT Fix 5 — authorization matrix (view timetable, no manage timetable)
    |--------------------------------------------------------------------------
    */

    public function test_view_only_user_is_forbidden_from_every_academic_calendar_mutation_route(): void
    {
        $user = $this->viewOnlyUser();
        $year = $this->activeYear();
        $calendar = $this->calendar($year);

        $this->actingAs($user)->get(route('dashboard.academic-calendars.create'))->assertForbidden();
        $this->actingAs($user)->post(route('dashboard.academic-calendars.store'), [
            'academic_year_id' => $year->id, 'weekly_days_off' => ['fri'],
        ])->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.academic-calendars.edit', $calendar))->assertForbidden();
        $this->actingAs($user)->put(route('dashboard.academic-calendars.update', $calendar), [
            'academic_year_id' => $year->id, 'weekly_days_off' => ['fri'],
        ])->assertForbidden();
    }

    public function test_view_only_user_is_forbidden_from_every_calendar_event_mutation_route(): void
    {
        $user = $this->viewOnlyUser();
        $year = $this->activeYear();
        $calendar = $this->calendar($year);
        $event = $calendar->events()->create([
            'name' => 'E', 'type' => 'official_holiday', 'start_date' => '2026-10-01', 'end_date' => '2026-10-01', 'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('dashboard.academic-calendars.events.create', $calendar))->assertForbidden();
        $this->actingAs($user)->post(route('dashboard.academic-calendars.events.store', $calendar), [
            'name' => 'E2', 'type' => 'official_holiday', 'start_date' => '2026-10-02', 'end_date' => '2026-10-02',
        ])->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.academic-calendars.events.edit', [$calendar, $event]))->assertForbidden();
        $this->actingAs($user)->put(route('dashboard.academic-calendars.events.update', [$calendar, $event]), [
            'name' => 'E', 'type' => 'official_holiday', 'start_date' => '2026-10-01', 'end_date' => '2026-10-01',
        ])->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-UAT Fix 6 — update success/integration
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_update_a_calendar_event(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $calendar = $this->calendar($year);
        $event = $calendar->events()->create([
            'name' => 'Before', 'type' => 'official_holiday', 'start_date' => '2026-10-01', 'end_date' => '2026-10-01', 'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->put(route('dashboard.academic-calendars.events.update', [$calendar, $event]), [
            'name' => 'After', 'type' => 'school_holiday', 'start_date' => '2026-10-05', 'end_date' => '2026-10-06', 'is_active' => '1',
        ]);

        $response->assertRedirect(route('dashboard.academic-calendars.edit', $calendar));
        $this->assertStringStartsWith(url('/dashboard'), $response->headers->get('Location'));

        $event->refresh();
        $this->assertSame('After', $event->name);
        $this->assertSame('school_holiday', $event->type);
    }

    public function test_updating_an_event_outside_the_academic_year_is_rejected(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $calendar = $this->calendar($year);
        $event = $calendar->events()->create([
            'name' => 'E', 'type' => 'official_holiday', 'start_date' => '2026-10-01', 'end_date' => '2026-10-01', 'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->put(route('dashboard.academic-calendars.events.update', [$calendar, $event]), [
            'name' => 'E', 'type' => 'official_holiday', 'start_date' => '2025-01-01', 'end_date' => '2025-01-01', 'is_active' => '1',
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame('2026-10-01', $event->fresh()->start_date->toDateString());
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-UAT Fix 7 — tampered bell-schedule references and constraints
    |--------------------------------------------------------------------------
    | These prove the shared model validation (AcademicCalendar::booted() /
    | CalendarEvent::booted()) is actually reached from the Dashboard
    | controllers, the same way a crafted request would bypass the <select>
    | options that normally only offer same-year, active choices.
    */

    public function test_default_bell_schedule_from_another_academic_year_is_rejected(): void
    {
        $admin = $this->adminUser();
        // BellSchedule also implements ResolvesAcademicYear, so — like the
        // calendar ownership fixtures above — the foreign schedule's year
        // must be briefly active to create it under AcademicYearLockObserver.
        $otherYear = AcademicYear::create([
            'name' => 'Other ' . uniqid(), 'start_date' => '2020-09-01', 'end_date' => '2021-05-31', 'is_active' => true,
        ]);
        $foreignSchedule = BellSchedule::create([
            'academic_year_id' => $otherYear->id, 'name' => 'Foreign', 'shift' => 1, 'is_active' => true,
        ]);
        $year = $this->activeYear();

        $response = $this->actingAs($admin)->post(route('dashboard.academic-calendars.store'), [
            'academic_year_id' => $year->id,
            'weekly_days_off' => ['fri', 'sat'],
            'default_bell_schedule_id' => $foreignSchedule->id,
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseMissing('academic_calendars', ['academic_year_id' => $year->id]);
    }

    public function test_calendar_event_bell_schedule_from_another_academic_year_is_rejected(): void
    {
        $admin = $this->adminUser();
        $otherYear = AcademicYear::create([
            'name' => 'Other ' . uniqid(), 'start_date' => '2020-09-01', 'end_date' => '2021-05-31', 'is_active' => true,
        ]);
        $foreignSchedule = BellSchedule::create([
            'academic_year_id' => $otherYear->id, 'name' => 'Foreign', 'shift' => 1, 'is_active' => true,
        ]);
        $year = $this->activeYear();
        $calendar = $this->calendar($year);

        $response = $this->actingAs($admin)->post(route('dashboard.academic-calendars.events.store', $calendar), [
            'name' => 'Override', 'type' => 'bell_schedule_override',
            'start_date' => '2026-10-01', 'end_date' => '2026-10-01',
            'bell_schedule_id' => $foreignSchedule->id,
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseMissing('calendar_events', ['academic_calendar_id' => $calendar->id, 'name' => 'Override']);
    }

    public function test_calendar_event_outside_the_academic_year_date_range_is_rejected(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $calendar = $this->calendar($year);

        $response = $this->actingAs($admin)->post(route('dashboard.academic-calendars.events.store', $calendar), [
            'name' => 'Outside', 'type' => 'official_holiday',
            'start_date' => '2025-01-01', 'end_date' => '2025-01-01',
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseMissing('calendar_events', ['academic_calendar_id' => $calendar->id, 'name' => 'Outside']);
    }

    public function test_bell_schedule_override_without_a_bell_schedule_is_rejected(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $calendar = $this->calendar($year);

        $response = $this->actingAs($admin)->post(route('dashboard.academic-calendars.events.store', $calendar), [
            'name' => 'Override', 'type' => 'bell_schedule_override',
            'start_date' => '2026-10-01', 'end_date' => '2026-10-01',
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseMissing('calendar_events', ['academic_calendar_id' => $calendar->id, 'name' => 'Override']);
    }

    public function test_bell_schedule_override_spanning_multiple_days_is_rejected(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $calendar = $this->calendar($year);
        $schedule = BellSchedule::create([
            'academic_year_id' => $year->id, 'name' => 'S', 'shift' => 1, 'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('dashboard.academic-calendars.events.store', $calendar), [
            'name' => 'Override', 'type' => 'bell_schedule_override',
            'start_date' => '2026-10-01', 'end_date' => '2026-10-02',
            'bell_schedule_id' => $schedule->id,
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseMissing('calendar_events', ['academic_calendar_id' => $calendar->id, 'name' => 'Override']);
    }
}
