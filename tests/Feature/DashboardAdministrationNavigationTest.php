<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 navigation fix: the sidebar's top-level "Администрирование" item
 * used to link straight into the Filament /admin panel
 * ($adminPanel->getUrl()), so any click on it took an administrative
 * dashboard user out of the /dashboard shell entirely. This covers the
 * replacement — a dashboard-native administration hub
 * (dashboard.administration.index) — and asserts that every Phase 1
 * migrated module stays inside /dashboard end to end.
 */
class DashboardAdministrationNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function adminUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    /**
     * Administrative-portal role (panel access is granted) that does NOT
     * hold 'manage teachers' — per RolesAndPermissionsSeeder, 'cashier'
     * gets panel access but no teacher-related permission at all.
     */
    protected function panelUserWithoutTeacherManagement(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('cashier');

        return $user;
    }

    public function test_top_level_administration_link_no_longer_points_at_the_filament_panel(): void
    {
        $admin = $this->adminUser();

        $adminPanel = \Filament\Facades\Filament::getPanel('admin');

        $html = (string) $this->actingAs($admin)->view('layouts.partials.shell-sidebar');

        $this->assertStringContainsString(route('dashboard.administration.index'), $html);
        $this->assertStringNotContainsString('href="' . $adminPanel->getUrl() . '"', $html);
    }

    public function test_administration_hub_is_reachable_and_stays_inside_the_dashboard_shell(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->get(route('dashboard.administration.index'));

        $response->assertOk();
        $response->assertSee('data-shell', false);
    }

    public function test_administration_hub_links_to_every_phase_1_academic_infrastructure_module(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->get(route('dashboard.administration.index'));

        $response->assertSee(route('dashboard.bell-schedules.index'), false);
        $response->assertSee(route('dashboard.academic-calendars.index'), false);
        $response->assertSee(route('dashboard.classrooms.index'), false);
    }

    public function test_administration_hub_marks_filament_links_as_a_technical_section_for_super_admins(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->get(route('dashboard.administration.index'));

        $response->assertSee(__('administration.section_technical'));
        $response->assertSee(route('filament.admin.resources.users.index'), false);
        $response->assertSee(route('filament.admin.resources.roles.index'), false);
    }

    public function test_guest_cannot_access_the_administration_hub(): void
    {
        $this->get(route('dashboard.administration.index'))->assertRedirect(route('login'));
    }

    public function test_phase_1_migrated_routes_do_not_redirect_into_admin(): void
    {
        $admin = $this->adminUser();

        foreach ([
            'dashboard.academic-years.index',
            'dashboard.classes.index',
            'dashboard.subjects.index',
            'dashboard.curricula.index',
            'dashboard.teachers.index',
            'dashboard.bell-schedules.index',
            'dashboard.academic-calendars.index',
            'dashboard.classrooms.index',
            'dashboard.administration.index',
        ] as $routeName) {
            $response = $this->actingAs($admin)->get(route($routeName));

            $response->assertOk();
            $this->assertStringNotContainsString('/admin', $response->headers->get('Location') ?? '');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-UAT Fix 3 — sidebar Filament leakage
    |--------------------------------------------------------------------------
    */

    public function test_users_and_roles_are_no_longer_direct_links_in_the_main_sidebar(): void
    {
        $admin = $this->adminUser();

        $html = (string) $this->actingAs($admin)->view('layouts.partials.shell-sidebar');

        $this->assertStringNotContainsString(route('filament.admin.resources.users.index'), $html);
        $this->assertStringNotContainsString(route('filament.admin.resources.roles.index'), $html);
    }

    public function test_users_and_roles_are_only_reachable_through_the_administration_hub(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->get(route('dashboard.administration.index'));

        $response->assertSee(route('filament.admin.resources.users.index'), false);
        $response->assertSee(route('filament.admin.resources.roles.index'), false);
    }

    public function test_teacher_assignments_link_is_hidden_without_manage_teachers_permission(): void
    {
        $user = $this->panelUserWithoutTeacherManagement();

        $html = (string) $this->actingAs($user)->view('layouts.partials.shell-sidebar');

        $this->assertStringNotContainsString(route('filament.admin.resources.teacher-assignments.index'), $html);
    }

    public function test_teacher_assignments_link_is_visible_with_manage_teachers_permission(): void
    {
        $admin = $this->adminUser();

        $html = (string) $this->actingAs($admin)->view('layouts.partials.shell-sidebar');

        $this->assertStringContainsString(route('filament.admin.resources.teacher-assignments.index'), $html);
    }

    /** Proves the sidebar's visibility check actually matches what happens at the destination. */
    public function test_teacher_assignments_destination_matches_its_sidebar_visibility(): void
    {
        $withoutPermission = $this->panelUserWithoutTeacherManagement();
        $this->actingAs($withoutPermission)
            ->get(route('filament.admin.resources.teacher-assignments.index'))
            ->assertForbidden();

        $withPermission = $this->adminUser();
        $this->actingAs($withPermission)
            ->get(route('filament.admin.resources.teacher-assignments.index'))
            ->assertOk();
    }

    public function test_teacher_salaries_and_buses_links_are_hidden_for_a_non_administrative_user(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $teacher = User::factory()->create(['is_active' => true]);
        $teacher->assignRole('teacher');
        \App\Models\Teacher::create([
            'user_id' => $teacher->id, 'first_name' => 'T', 'last_name' => 'Teacher-' . uniqid(), 'is_active' => true,
        ]);

        $html = (string) $this->actingAs($teacher)->view('layouts.partials.shell-sidebar');

        $this->assertStringNotContainsString(route('filament.admin.resources.teacher-salaries.index'), $html);
        $this->assertStringNotContainsString(route('filament.admin.resources.buses.index'), $html);
    }

    public function test_employee_payroll_requires_its_dedicated_view_permission(): void
    {
        $cashier = $this->panelUserWithoutTeacherManagement();

        $html = (string) $this->actingAs($cashier)->view('layouts.partials.shell-sidebar');
        $this->assertStringNotContainsString(route('dashboard.salaries.index'), $html);
        $this->assertStringContainsString(route('filament.admin.resources.buses.index'), $html);

        $this->actingAs($cashier)->get(route('filament.admin.resources.teacher-salaries.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('dashboard.salaries.index'))->assertForbidden();
        $cashier->givePermissionTo('view payroll');
        $html = (string) $this->actingAs($cashier)->view('layouts.partials.shell-sidebar');
        // Payroll now stays inside the unified dashboard shell — no raw
        // cross-panel href into Filament — unlike Buses, which is still
        // out of Phase 1 scope.
        $this->assertStringContainsString(route('dashboard.salaries.index'), $html);
        $this->assertStringNotContainsString(route('filament.admin.resources.teacher-salaries.index'), $html);

        $this->actingAs($cashier)->get(route('dashboard.salaries.index'))->assertOk();
        $this->actingAs($cashier)->get(route('filament.admin.resources.teacher-salaries.index'))->assertOk();
        $this->actingAs($cashier)->get(route('filament.admin.resources.buses.index'))->assertOk();
    }
}
