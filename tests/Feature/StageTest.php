<?php

namespace Tests\Feature;

use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StageTest extends TestCase
{
    use RefreshDatabase;

    protected function authorizedUser(): User
    {
        $user = User::factory()->create();

        // Matches the permission seeded in RolesAndPermissionsSeeder.php.
        Permission::findOrCreate('manage stages', 'web');
        $user->givePermissionTo('manage stages');

        return $user;
    }

    public function test_any_authenticated_user_can_view_the_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.stages.index'));

        $response->assertOk();
    }

    public function test_unauthorized_user_cannot_open_create_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.stages.create'));

        $response->assertForbidden();
    }

    public function test_unauthorized_user_cannot_store_a_stage(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('dashboard.stages.store'), [
            'name' => 'Primary',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('stages', 0);
    }

    public function test_authorized_user_can_store_a_stage(): void
    {
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->post(route('dashboard.stages.store'), [
            'name' => 'Primary',
        ]);

        $response->assertRedirect(route('dashboard.stages.index'));
        $this->assertDatabaseHas('stages', ['name' => 'Primary']);
    }

    public function test_authorized_user_can_update_a_stage(): void
    {
        $user = $this->authorizedUser();
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);

        $response = $this->actingAs($user)->put(route('dashboard.stages.update', $stage), [
            'name' => 'Primary Renamed',
        ]);

        $response->assertRedirect(route('dashboard.stages.index'));
        $this->assertSame('Primary Renamed', $stage->fresh()->name);
    }

    public function test_unauthorized_user_cannot_delete_a_stage(): void
    {
        $user = $this->authorizedUser();
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);

        // Revoke permission to confirm destroy is actually gated.
        $user->revokePermissionTo('manage stages');

        $response = $this->actingAs($user)->delete(route('dashboard.stages.destroy', $stage));

        $response->assertForbidden();
        $this->assertDatabaseHas('stages', ['id' => $stage->id]);
    }
}
