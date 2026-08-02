<?php

namespace Tests\Feature\Leadership;

use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SuperAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
    }

    protected function user(string $role, bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active]);
        $user->assignRole($role);

        return $user;
    }

    public function test_active_super_admin_enters_admin_surfaces_but_not_teacher_panel_by_default(): void
    {
        $user = $this->user('super-admin');

        $this->actingAs($user)->get('/admin')->assertOk();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/teacher')->assertForbidden();
    }

    public function test_super_admin_may_enter_teacher_panel_only_with_teacher_role_and_active_link(): void
    {
        $user = $this->user('super-admin');
        $user->assignRole('teacher');
        Teacher::create([
            'user_id' => $user->id,
            'first_name' => 'Anna',
            'last_name' => 'Ivanova',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/teacher')->assertOk();
    }

    public function test_active_super_admin_bypasses_an_unassigned_ordinary_permission(): void
    {
        $user = $this->user('super-admin');
        Permission::create(['name' => 'leadership test permission']);

        $this->assertTrue($user->can('leadership test permission'));
        $this->assertTrue($user->hasPermissionTo('leadership test permission'));
    }

    public function test_disabled_leadership_users_are_denied_from_panels_and_dashboard(): void
    {
        foreach (['super-admin', 'principal'] as $role) {
            $user = $this->user($role, active: false);

            $this->actingAs($user)->get('/admin')->assertForbidden();
            $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
            $this->assertGuest();
        }
    }
}
