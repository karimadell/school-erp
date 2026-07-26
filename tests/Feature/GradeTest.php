<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GradeTest extends TestCase
{
    use RefreshDatabase;

    protected function makeStage(): Stage
    {
        return Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);
    }

    protected function authorizedUser(): User
    {
        $user = User::factory()->create();

        // Matches the permission seeded in RolesAndPermissionsSeeder.php.
        Permission::findOrCreate('manage grades', 'web');
        $user->givePermissionTo('manage grades');

        return $user;
    }

    public function test_fillable_matches_real_grades_columns(): void
    {
        // Regression test: Grade::$fillable used to list 'order' and
        // 'is_active', which are not columns on the grades table (that
        // schema only has stage_id/name/level) — dead fillable entries
        // left over from an abandoned migration attempt.
        $this->assertSame(['name', 'stage_id'], (new Grade())->getFillable());
    }

    public function test_any_authenticated_user_can_view_the_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.grades.index'));

        $response->assertOk();
    }

    public function test_unauthorized_user_cannot_open_create_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.grades.create'));

        $response->assertForbidden();
    }

    public function test_unauthorized_user_cannot_store_a_grade(): void
    {
        $user = User::factory()->create();
        $stage = $this->makeStage();

        $response = $this->actingAs($user)->post(route('dashboard.grades.store'), [
            'stage_id' => $stage->id,
            'name' => 'Grade 1',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('grades', 0);
    }

    public function test_unauthorized_user_cannot_delete_a_grade(): void
    {
        $user = $this->authorizedUser();
        $stage = $this->makeStage();
        $grade = Grade::create(['stage_id' => $stage->id, 'name' => 'Grade 1']);

        // Revoke permission to confirm destroy is actually gated.
        $user->revokePermissionTo('manage grades');

        $response = $this->actingAs($user)->delete(route('dashboard.grades.destroy', $grade));

        $response->assertForbidden();
        $this->assertDatabaseHas('grades', ['id' => $grade->id]);
    }

    public function test_grade_can_be_created_via_controller(): void
    {
        $user = $this->authorizedUser();
        $stage = $this->makeStage();

        $response = $this->actingAs($user)->post(route('dashboard.grades.store'), [
            'stage_id' => $stage->id,
            'name' => 'Grade 1',
        ]);

        $response->assertRedirect(route('dashboard.grades.index'));

        $this->assertDatabaseHas('grades', [
            'stage_id' => $stage->id,
            'name' => 'Grade 1',
        ]);
    }

    public function test_grade_can_be_updated_via_controller(): void
    {
        $user = $this->authorizedUser();
        $stage = $this->makeStage();
        $grade = Grade::create(['stage_id' => $stage->id, 'name' => 'Grade 1']);

        $response = $this->actingAs($user)->put(route('dashboard.grades.update', $grade), [
            'stage_id' => $stage->id,
            'name' => 'Grade 1 Renamed',
        ]);

        $response->assertRedirect(route('dashboard.grades.index'));
        $this->assertSame('Grade 1 Renamed', $grade->fresh()->name);
    }

    public function test_grade_can_be_deleted_via_controller(): void
    {
        $user = $this->authorizedUser();
        $stage = $this->makeStage();
        $grade = Grade::create(['stage_id' => $stage->id, 'name' => 'Grade 1']);

        $response = $this->actingAs($user)->delete(route('dashboard.grades.destroy', $grade));

        $response->assertRedirect(route('dashboard.grades.index'));
        $this->assertDatabaseMissing('grades', ['id' => $grade->id]);
    }
}
