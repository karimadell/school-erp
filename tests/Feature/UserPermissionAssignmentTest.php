<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Batch 7: admin-controlled individual employee permissions. Spatie
 * already supports direct, per-user permissions (additive only, unioned
 * with role permissions — option A, approved) via model_has_permissions;
 * this batch verifies the existing Filament UI actually works, fixes the
 * password-required-on-edit bug that blocked this exact workflow, and
 * closes the audit gap (BelongsToMany syncs never fire Eloquent model
 * events, so the already-registered AuditObserver cannot see them).
 */
class UserPermissionAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        (new RolesAndPermissionsSeeder())->run();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /*
    |--------------------------------------------------------------------------
    | Direct permission assignment — additive, independent of role
    |--------------------------------------------------------------------------
    */

    public function test_a_direct_permission_can_be_assigned_via_the_filament_form(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create();
        Permission::findOrCreate('view invoices', 'web');

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $employee->id])
            ->fillForm(['permissions' => [Permission::where('name', 'view invoices')->first()->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($employee->fresh()->can('view invoices'));
    }

    public function test_a_direct_permission_can_be_revoked_via_the_filament_form(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create();
        $permission = Permission::findOrCreate('view invoices', 'web');
        $employee->givePermissionTo($permission);

        $this->assertTrue($employee->fresh()->can('view invoices'));

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $employee->id])
            ->fillForm(['permissions' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($employee->fresh()->can('view invoices'));
    }

    public function test_direct_permissions_and_role_permissions_are_independent(): void
    {
        $employee = User::factory()->create();
        Role::findOrCreate('reception', 'web')->syncPermissions([
            Permission::findOrCreate('view students', 'web'),
        ]);
        $employee->assignRole('reception');
        $employee->givePermissionTo(Permission::findOrCreate('view invoices', 'web'));

        // Removing the direct permission doesn't touch the role's grant.
        $employee->revokePermissionTo('view invoices');
        $this->assertTrue($employee->fresh()->can('view students'));
        $this->assertFalse($employee->fresh()->can('view invoices'));

        // Removing the role doesn't touch the direct grant.
        $employee->givePermissionTo('view invoices');
        $employee->removeRole('reception');
        $this->assertFalse($employee->fresh()->can('view students'));
        $this->assertTrue($employee->fresh()->can('view invoices'));
    }

    public function test_option_a_direct_permissions_cannot_subtract_from_a_role(): void
    {
        // Documents the approved semantics: a direct grant can only add,
        // never override/deny a permission the role already provides.
        // There is no "revoke this specific role-permission for just this
        // user" mechanism — Spatie's hasPermissionTo() is a pure OR.
        $employee = User::factory()->create();
        Role::findOrCreate('reception', 'web')->syncPermissions([
            Permission::findOrCreate('view students', 'web'),
        ]);
        $employee->assignRole('reception');

        $this->assertTrue($employee->can('view students'));

        // No API exists to deny 'view students' for this one user while
        // keeping the reception role — confirmed by absence, not by a
        // negative test of a nonexistent method.
        $this->assertTrue(method_exists($employee, 'hasDirectPermission'));
        $this->assertTrue($employee->can('view students'));
    }

    /*
    |--------------------------------------------------------------------------
    | Password bug fix
    |--------------------------------------------------------------------------
    */

    public function test_editing_a_user_without_a_new_password_succeeds_and_keeps_the_old_one(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create(['password' => Hash::make('original-password')]);
        $originalHash = $employee->password;

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $employee->id])
            ->fillForm(['name' => 'Updated Name', 'password' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated Name', $employee->fresh()->name);
        $this->assertSame($originalHash, $employee->fresh()->password);
    }

    public function test_editing_a_user_with_a_new_password_updates_it(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create(['password' => Hash::make('original-password')]);
        $originalHash = $employee->password;

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $employee->id])
            ->fillForm(['password' => 'brand-new-password'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotSame($originalHash, $employee->fresh()->password);
        $this->assertTrue(Hash::check('brand-new-password', $employee->fresh()->password));
    }

    public function test_creating_a_user_still_requires_a_password(): void
    {
        $admin = $this->superAdmin();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm(['name' => 'New User', 'email' => 'new@example.com', 'password' => null])
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    /*
    |--------------------------------------------------------------------------
    | Audit trail — pivot syncs don't fire model events, so this must be explicit
    |--------------------------------------------------------------------------
    */

    public function test_a_permission_change_via_edit_creates_an_audit_log_entry(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create();
        $permission = Permission::findOrCreate('view invoices', 'web');

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $employee->id])
            ->fillForm(['permissions' => [$permission->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'permissions_updated',
            'model' => 'User',
            'model_id' => $employee->id,
            'user_id' => $admin->id,
        ]);

        $log = AuditLog::where('model_id', $employee->id)->where('action', 'permissions_updated')->first();
        $this->assertContains('view invoices', $log->new_values['permissions']);
        $this->assertNotContains('view invoices', $log->old_values['permissions']);
    }

    public function test_saving_with_no_actual_permission_change_does_not_create_a_noisy_audit_entry(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $employee->id])
            ->fillForm(['name' => $employee->name])
            ->call('save')
            ->assertHasNoFormErrors();

        // The pre-existing AuditObserver still logs the plain 'updated'
        // event (registered on User since before this batch) — what this
        // proves is that no *additional*, noisy 'permissions_updated'
        // entry is created when nothing about roles/permissions changed.
        $this->assertDatabaseMissing('audit_logs', [
            'model_id' => $employee->id,
            'action' => 'permissions_updated',
        ]);
    }

    public function test_creating_a_user_with_a_role_creates_an_audit_log_entry(): void
    {
        $admin = $this->superAdmin();
        $role = Role::findOrCreate('reception', 'web');

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'New Employee',
                'email' => 'employee@example.com',
                'password' => 'a-strong-password',
                'roles' => [$role->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $newUser = User::where('email', 'employee@example.com')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'permissions_assigned',
            'model' => 'User',
            'model_id' => $newUser->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Table visibility + authorization
    |--------------------------------------------------------------------------
    */

    public function test_users_table_shows_direct_permissions_distinctly_from_roles(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create(['name' => 'Permission Holder']);
        $employee->givePermissionTo(Permission::findOrCreate('view invoices', 'web'));

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertSuccessful()
            ->assertSee('Permission Holder')
            ->assertSee('view invoices');
    }

    public function test_a_non_super_admin_cannot_reach_the_user_edit_form(): void
    {
        $reception = User::factory()->create();
        Role::findOrCreate('reception', 'web');
        $reception->assignRole('reception');
        $employee = User::factory()->create();

        Livewire::actingAs($reception)
            ->test(EditUser::class, ['record' => $employee->id])
            ->assertForbidden();
    }
}
