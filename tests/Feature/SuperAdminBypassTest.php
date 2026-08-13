<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassResource\Pages\TimetableGrid;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Fees\Pages\ListFees;
use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Alpha testing: proves 'super-admin' is a true, automatic bypass of every
 * permission check — including a permission that exists but is assigned to
 * no role, which the seeded-permission-list assertions in
 * AuthorizationMatrixTest can't distinguish from "the role just happens to
 * hold every permission the seeder currently lists" (ADR-004: 'super-admin'
 * is the canonical bypass; 'admin' merely holds the full seeded permission
 * set). Also proves non-super-admin roles are unaffected by the bypass.
 */
class SuperAdminBypassTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithRole(string $role): User
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_super_admin_can_do_a_brand_new_permission_assigned_to_no_role(): void
    {
        $admin = $this->userWithRole('super-admin');

        $permission = Permission::create(['name' => 'brand new alpha permission']);

        $this->assertTrue($admin->can($permission->name));
    }

    public function test_super_admin_bypass_covers_direct_hasPermissionTo_and_hasAnyPermission_calls(): void
    {
        $admin = $this->userWithRole('super-admin');

        Permission::create(['name' => 'another unassigned permission']);

        $this->assertTrue($admin->hasPermissionTo('another unassigned permission'));
        $this->assertTrue($admin->hasAnyPermission(['another unassigned permission']));
    }

    public function test_super_admin_can_open_system_configuration_filament_resources(): void
    {
        $admin = $this->userWithRole('admin');

        Livewire::actingAs($admin)->test(ListRoles::class)->assertSuccessful();
        Livewire::actingAs($admin)->test(ListPermissions::class)->assertSuccessful();
    }

    public function test_super_admin_can_open_operational_filament_resources(): void
    {
        $admin = $this->userWithRole('admin');

        Livewire::actingAs($admin)->test(ListFees::class)->assertSuccessful();
        Livewire::actingAs($admin)->test(ListExpenses::class)->assertSuccessful();
    }

    public function test_super_admin_passes_the_timetable_grid_defense_in_depth_check(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin);

        $this->assertTrue(TimetableGrid::canAccess());
    }

    public function test_non_admin_roles_are_not_affected_by_the_super_admin_bypass(): void
    {
        Permission::create(['name' => 'yet another unassigned permission']);

        $schoolAdmin = $this->userWithRole('school-admin');
        $this->assertFalse($schoolAdmin->can('manage roles'));
        $this->assertFalse($schoolAdmin->can('yet another unassigned permission'));
        Livewire::actingAs($schoolAdmin)->test(ListRoles::class)->assertForbidden();

        $reception = $this->userWithRole('reception');
        $this->assertFalse($reception->can('manage permissions'));
        Livewire::actingAs($reception)->test(ListFees::class)->assertForbidden();

        $teacher = $this->userWithRole('teacher');
        $this->assertFalse($teacher->can('manage roles'));
        $this->actingAs($teacher);
        $this->assertFalse(TimetableGrid::canAccess());
    }
}
