<?php

namespace Tests\Feature;

use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TeacherTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A portal-eligible but unprivileged user: active and holding an
     * administrative role ('reception') so EnsureAdministrativePortalAccess
     * lets the request through to the controller, but WITHOUT 'manage
     * teachers' — so the authorization gate itself is what the negative
     * tests exercise (a real 403), not a portal redirect.
     */
    protected function portalUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('reception');

        return $user;
    }

    protected function authorizedUser(): User
    {
        $user = $this->portalUser();

        Permission::findOrCreate('manage teachers', 'web');
        $user->givePermissionTo('manage teachers');

        return $user;
    }

    public function test_any_authenticated_user_can_view_the_index(): void
    {
        $user = $this->portalUser();

        $response = $this->actingAs($user)->get(route('dashboard.teachers.index'));

        $response->assertOk();
    }

    public function test_unauthorized_user_cannot_open_create_page(): void
    {
        $user = $this->portalUser();

        $response = $this->actingAs($user)->get(route('dashboard.teachers.create'));

        $response->assertForbidden();
    }

    public function test_unauthorized_user_cannot_store_a_teacher(): void
    {
        $user = $this->portalUser();

        $response = $this->actingAs($user)->post(route('dashboard.teachers.store'), [
            'first_name' => 'Anna',
            'last_name' => 'Sidorova',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('teachers', 0);
    }

    public function test_authorized_user_can_store_a_teacher(): void
    {
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->post(route('dashboard.teachers.store'), [
            'first_name' => 'Anna',
            'last_name' => 'Sidorova',
        ]);

        $response->assertRedirect(route('dashboard.teachers.index'));
        $this->assertDatabaseHas('teachers', ['first_name' => 'Anna', 'last_name' => 'Sidorova']);
    }

    public function test_unauthorized_user_cannot_delete_a_teacher(): void
    {
        $user = $this->authorizedUser();

        $teacher = Teacher::create([
            'first_name' => 'Anna',
            'last_name' => 'Sidorova',
            'is_active' => true,
        ]);

        // Revoke permission to confirm destroy is actually gated.
        $user->revokePermissionTo('manage teachers');

        $response = $this->actingAs($user)->delete(route('dashboard.teachers.destroy', $teacher));

        $response->assertForbidden();
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id]);
    }
}
