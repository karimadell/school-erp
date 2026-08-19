<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\BellSchedule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 dashboard migration: dashboard-native counterpart to
 * App\Filament\Resources\BellSchedules\BellScheduleResource, reached from
 * /dashboard/administration ("Расписания звонков") instead of only
 * /admin/bell-schedules. Reuses the exact same BellSchedule /
 * BellSchedulePeriod models and their validation — no business logic is
 * duplicated here.
 */
class DashboardBellScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function adminUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    /** Administrative-portal role without timetable permissions (school-admin). */
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

    protected function schedule(AcademicYear $year, array $overrides = []): BellSchedule
    {
        return BellSchedule::create(array_merge([
            'academic_year_id' => $year->id, 'name' => 'S-' . uniqid(), 'shift' => 1, 'is_active' => true,
        ], $overrides));
    }

    public function test_sidebar_bell_schedule_link_stays_inside_dashboard_not_admin(): void
    {
        $admin = $this->adminUser();

        $html = (string) $this->actingAs($admin)->view('layouts.partials.shell-sidebar');

        $this->assertStringContainsString(route('dashboard.bell-schedules.index'), $html);
        $this->assertStringNotContainsString('/admin/bell-schedules', $html);
    }

    public function test_authorized_administrative_user_can_view_the_list(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->get(route('dashboard.bell-schedules.index'))->assertOk();
    }

    public function test_direct_access_is_forbidden_without_timetable_permission(): void
    {
        $schoolAdmin = $this->schoolAdminUser();

        $this->actingAs($schoolAdmin)->get(route('dashboard.bell-schedules.index'))->assertForbidden();
    }

    public function test_view_only_user_can_list_but_not_create(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('school-admin');
        $user->givePermissionTo('view timetable');

        $this->actingAs($user)->get(route('dashboard.bell-schedules.index'))->assertOk();
        $this->actingAs($user)->get(route('dashboard.bell-schedules.create'))->assertForbidden();
    }

    public function test_guest_cannot_access_bell_schedules(): void
    {
        $this->get(route('dashboard.bell-schedules.index'))->assertRedirect(route('login'));
    }

    public function test_admin_can_create_a_bell_schedule(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();

        $response = $this->actingAs($admin)->post(route('dashboard.bell-schedules.store'), [
            'academic_year_id' => $year->id,
            'name' => 'Основное расписание',
            'shift' => 1,
            'is_default' => '1',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('dashboard.bell-schedules.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('bell_schedules', [
            'academic_year_id' => $year->id,
            'name' => 'Основное расписание',
            'shift' => 1,
        ]);
    }

    public function test_admin_can_add_a_period_to_an_existing_schedule(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $schedule = BellSchedule::create([
            'academic_year_id' => $year->id, 'name' => 'S1', 'shift' => 1, 'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('dashboard.bell-schedules.periods.store', $schedule), [
            'period_number' => 1,
            'label' => '1-й урок',
            'starts_at' => '08:00',
            'ends_at' => '08:45',
            'break_after_minutes' => 10,
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('dashboard.bell-schedules.edit', $schedule));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('bell_schedule_periods', [
            'bell_schedule_id' => $schedule->id,
            'period_number' => 1,
        ]);
    }

    public function test_overlapping_period_is_rejected_by_the_shared_model_validation(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $schedule = BellSchedule::create([
            'academic_year_id' => $year->id, 'name' => 'S1', 'shift' => 1, 'is_active' => true,
        ]);
        $schedule->periods()->create([
            'period_number' => 1, 'starts_at' => '08:00', 'ends_at' => '08:45', 'break_after_minutes' => 0,
        ]);

        $response = $this->actingAs($admin)->post(route('dashboard.bell-schedules.periods.store', $schedule), [
            'period_number' => 2,
            'starts_at' => '08:30',
            'ends_at' => '09:15',
            'break_after_minutes' => 0,
            'is_active' => '1',
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(1, $schedule->periods()->count());
    }

    public function test_edit_page_never_leaves_the_dashboard_shell(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $schedule = BellSchedule::create([
            'academic_year_id' => $year->id, 'name' => 'S1', 'shift' => 1, 'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard.bell-schedules.edit', $schedule));

        $response->assertOk();
        $response->assertSee('data-shell', false);
        $response->assertDontSee('/admin/bell-schedules', false);
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
        $schedule = $this->schedule($year);

        $html = (string) $this->actingAs($user)->get(route('dashboard.bell-schedules.index'))->getContent();

        $this->assertStringNotContainsString(route('dashboard.bell-schedules.edit', $schedule), $html);
    }

    public function test_manage_user_does_see_edit_action_on_the_list(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $schedule = $this->schedule($year);

        $html = (string) $this->actingAs($admin)->get(route('dashboard.bell-schedules.index'))->getContent();

        $this->assertStringContainsString(route('dashboard.bell-schedules.edit', $schedule), $html);
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-UAT Fix 4 — nested period ownership regression
    |--------------------------------------------------------------------------
    */

    public function test_editing_a_period_through_a_schedule_it_does_not_belong_to_is_not_found(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $scheduleA = $this->schedule($year, ['name' => 'A']);
        $scheduleB = $this->schedule($year, ['name' => 'B', 'shift' => 2]);
        $periodB = $scheduleB->periods()->create([
            'period_number' => 1, 'starts_at' => '08:00', 'ends_at' => '08:45', 'break_after_minutes' => 0,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard.bell-schedules.periods.edit', [$scheduleA, $periodB]))
            ->assertNotFound();
    }

    public function test_updating_a_period_through_a_schedule_it_does_not_belong_to_is_not_found(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $scheduleA = $this->schedule($year, ['name' => 'A']);
        $scheduleB = $this->schedule($year, ['name' => 'B', 'shift' => 2]);
        $periodB = $scheduleB->periods()->create([
            'period_number' => 1, 'starts_at' => '08:00', 'ends_at' => '08:45', 'break_after_minutes' => 0,
        ]);

        $this->actingAs($admin)
            ->put(route('dashboard.bell-schedules.periods.update', [$scheduleA, $periodB]), [
                'period_number' => 1, 'starts_at' => '09:00', 'ends_at' => '09:45', 'break_after_minutes' => 0,
            ])
            ->assertNotFound();

        $this->assertSame('08:00', $periodB->fresh()->starts_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-UAT Fix 5 — authorization matrix (view timetable, no manage timetable)
    |--------------------------------------------------------------------------
    */

    public function test_view_only_user_is_forbidden_from_every_bell_schedule_mutation_route(): void
    {
        $user = $this->viewOnlyUser();
        $year = $this->activeYear();
        $schedule = $this->schedule($year);

        $this->actingAs($user)->get(route('dashboard.bell-schedules.create'))->assertForbidden();
        $this->actingAs($user)->post(route('dashboard.bell-schedules.store'), [
            'academic_year_id' => $year->id, 'name' => 'X', 'shift' => 1,
        ])->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.bell-schedules.edit', $schedule))->assertForbidden();
        $this->actingAs($user)->put(route('dashboard.bell-schedules.update', $schedule), [
            'academic_year_id' => $year->id, 'name' => 'X', 'shift' => 1,
        ])->assertForbidden();
    }

    public function test_view_only_user_is_forbidden_from_every_bell_schedule_period_mutation_route(): void
    {
        $user = $this->viewOnlyUser();
        $year = $this->activeYear();
        $schedule = $this->schedule($year);
        $period = $schedule->periods()->create([
            'period_number' => 1, 'starts_at' => '08:00', 'ends_at' => '08:45', 'break_after_minutes' => 0,
        ]);

        $this->actingAs($user)->get(route('dashboard.bell-schedules.periods.create', $schedule))->assertForbidden();
        $this->actingAs($user)->post(route('dashboard.bell-schedules.periods.store', $schedule), [
            'period_number' => 2, 'starts_at' => '09:00', 'ends_at' => '09:45', 'break_after_minutes' => 0,
        ])->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.bell-schedules.periods.edit', [$schedule, $period]))->assertForbidden();
        $this->actingAs($user)->put(route('dashboard.bell-schedules.periods.update', [$schedule, $period]), [
            'period_number' => 1, 'starts_at' => '08:00', 'ends_at' => '08:30', 'break_after_minutes' => 0,
        ])->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-UAT Fix 6 — update success/integration
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_update_a_bell_schedule_and_stays_inside_the_dashboard(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $schedule = $this->schedule($year, ['name' => 'Before']);

        $response = $this->actingAs($admin)->put(route('dashboard.bell-schedules.update', $schedule), [
            'academic_year_id' => $year->id,
            'name' => 'After',
            'shift' => 2,
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('dashboard.bell-schedules.edit', $schedule));
        $this->assertStringStartsWith(url('/dashboard'), $response->headers->get('Location'));
        $response->assertSessionHas('success');

        $schedule->refresh();
        $this->assertSame('After', $schedule->name);
        $this->assertSame(2, $schedule->shift);
    }

    public function test_admin_can_update_a_bell_schedule_period(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $schedule = $this->schedule($year);
        $period = $schedule->periods()->create([
            'period_number' => 1, 'label' => 'Old', 'starts_at' => '08:00', 'ends_at' => '08:45', 'break_after_minutes' => 0,
        ]);

        $response = $this->actingAs($admin)->put(route('dashboard.bell-schedules.periods.update', [$schedule, $period]), [
            'period_number' => 1, 'label' => 'New', 'starts_at' => '08:15', 'ends_at' => '09:00', 'break_after_minutes' => 5,
        ]);

        $response->assertRedirect(route('dashboard.bell-schedules.edit', $schedule));
        $this->assertStringStartsWith(url('/dashboard'), $response->headers->get('Location'));

        $period->refresh();
        $this->assertSame('New', $period->label);
        $this->assertSame('08:15', $period->starts_at);
        $this->assertSame(5, $period->break_after_minutes);
    }

    public function test_updating_a_period_to_overlap_another_is_rejected(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $schedule = $this->schedule($year);
        $schedule->periods()->create([
            'period_number' => 1, 'starts_at' => '08:00', 'ends_at' => '08:45', 'break_after_minutes' => 0,
        ]);
        $period2 = $schedule->periods()->create([
            'period_number' => 2, 'starts_at' => '09:00', 'ends_at' => '09:45', 'break_after_minutes' => 0,
        ]);

        $response = $this->actingAs($admin)->put(route('dashboard.bell-schedules.periods.update', [$schedule, $period2]), [
            'period_number' => 2, 'starts_at' => '08:30', 'ends_at' => '09:15', 'break_after_minutes' => 0,
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame('09:00', $period2->fresh()->starts_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Active/inactive bell schedule question — documented current behavior.
    | Neither the model (BellSchedule::booted()) nor
    | CurriculumAwareTimetableConflictChecker enforce is_active on the bell
    | schedule referenced by an AcademicCalendar/CalendarEvent — only the
    | Filament <select>/Dashboard <select> options are filtered to active
    | rows for data-entry convenience. This test documents that today's
    | domain layer does NOT reject a same-year inactive schedule reference;
    | see the final report for the product-decision recommendation. Not a
    | rule this fix pass invents or enforces.
    |--------------------------------------------------------------------------
    */
    public function test_an_inactive_same_year_bell_schedule_is_not_currently_rejected_by_the_domain_layer(): void
    {
        $admin = $this->adminUser();
        $year = $this->activeYear();
        $inactiveSchedule = $this->schedule($year, ['name' => 'Inactive', 'is_active' => false]);

        $response = $this->actingAs($admin)->post(route('dashboard.academic-calendars.store'), [
            'academic_year_id' => $year->id,
            'weekly_days_off' => ['fri', 'sat'],
            'default_bell_schedule_id' => $inactiveSchedule->id,
        ]);

        $response->assertRedirect(route('dashboard.academic-calendars.index'));
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('academic_calendars', [
            'academic_year_id' => $year->id,
            'default_bell_schedule_id' => $inactiveSchedule->id,
        ]);
    }
}
