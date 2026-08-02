<?php

namespace Tests\Feature\Leadership;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Support\LeadershipAuthorization;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SuperAdminProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
    }

    protected function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_principal_cannot_assign_super_admin_through_a_crafted_role_payload(): void
    {
        $principal = $this->user('principal');
        $employee = User::factory()->create();
        $superAdminRole = Role::findByName('super-admin');

        $this->expectException(AuthorizationException::class);
        LeadershipAuthorization::authorizeRoleAssignment($principal, [$superAdminRole->id], $employee);
    }

    public function test_principal_cannot_escalate_through_another_role_with_protected_permissions(): void
    {
        $principal = $this->user('principal');
        $adminRole = Role::findByName('admin');

        $this->expectException(AuthorizationException::class);
        LeadershipAuthorization::authorizeRoleAssignment($principal, [$adminRole->id]);
    }

    public function test_filament_edit_action_rejects_a_crafted_super_admin_assignment(): void
    {
        $principal = $this->user('principal');
        $employee = User::factory()->create();
        $superAdminRole = Role::findByName('super-admin');

        Livewire::actingAs($principal)
            ->test(EditUser::class, ['record' => $employee->id])
            ->fillForm(['roles' => [$superAdminRole->id]])
            ->call('save')
            ->assertHasFormErrors();

        $this->assertFalse($employee->fresh()->isSuperAdmin());
    }

    public function test_principal_cannot_edit_delete_or_remove_role_from_super_admin(): void
    {
        $principal = $this->user('principal');
        $superAdmin = $this->user('super-admin');
        $ordinaryRole = Role::findByName('admin');

        $this->assertFalse(Gate::forUser($principal)->allows('update', $superAdmin));
        $this->assertFalse(Gate::forUser($principal)->allows('delete', $superAdmin));

        try {
            LeadershipAuthorization::authorizeRoleAssignment($principal, [$ordinaryRole->id], $superAdmin);
            $this->fail('Principal removed the protected super-admin role.');
        } catch (AuthorizationException) {
            $this->assertTrue($superAdmin->fresh()->hasRole('super-admin'));
        }

        Livewire::actingAs($principal)
            ->test(EditUser::class, ['record' => $superAdmin->id])
            ->assertForbidden();
    }

    public function test_principal_cannot_modify_or_delete_super_admin_role(): void
    {
        $principal = $this->user('principal');
        $role = Role::findByName('super-admin');

        $this->assertFalse(Gate::forUser($principal)->allows('update', $role));
        $this->assertFalse(Gate::forUser($principal)->allows('delete', $role));
    }

    public function test_non_super_admin_cannot_modify_permissions_protecting_super_admin(): void
    {
        $admin = $this->user('admin');
        $superAdmin = $this->user('super-admin');
        $permission = Permission::findByName('manage permissions');

        $this->assertFalse(Gate::forUser($admin)->allows('update', $permission));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $permission));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $permission));
    }

    public function test_last_active_super_admin_cannot_be_deleted_or_demoted(): void
    {
        $superAdmin = $this->user('super-admin');
        $adminRole = Role::findByName('admin');

        $this->assertTrue($superAdmin->isLastActiveSuperAdmin());
        $this->assertFalse(Gate::forUser($superAdmin)->allows('delete', $superAdmin));

        $this->expectException(AuthorizationException::class);
        LeadershipAuthorization::authorizeRoleAssignment($superAdmin, [$adminRole->id], $superAdmin);
    }

    public function test_super_admin_can_manage_protected_assignment_when_another_active_super_admin_remains(): void
    {
        $actor = $this->user('super-admin');
        $target = $this->user('super-admin');
        $adminRole = Role::findByName('admin');

        $this->assertTrue(Gate::forUser($actor)->allows('update', $target));
        LeadershipAuthorization::authorizeRoleAssignment($actor, [$adminRole->id], $target);
        $target->syncRoles([$adminRole]);

        $this->assertFalse($target->fresh()->isSuperAdmin());
        $this->assertTrue($actor->fresh()->isSuperAdmin());
    }
}
