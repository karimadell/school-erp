<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
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

    /**
     * Portal-eligible but unprivileged ('reception', active): clears
     * EnsureAdministrativePortalAccess but lacks 'manage grades', so the
     * negative tests exercise the real 403 gate, not a portal redirect.
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
        $user = $this->portalUser();

        $response = $this->actingAs($user)->get(route('dashboard.grades.index'));

        $response->assertOk();
    }

    public function test_unauthorized_user_cannot_open_create_page(): void
    {
        $user = $this->portalUser();

        $response = $this->actingAs($user)->get(route('dashboard.grades.create'));

        $response->assertForbidden();
    }

    public function test_unauthorized_user_cannot_store_a_grade(): void
    {
        $user = $this->portalUser();
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

    public function test_scoped_create_preselects_stage_and_returns_to_structure(): void
    {
        $user = $this->authorizedUser();
        $stage = $this->makeStage();
        $conflictingStage = Stage::create(['name' => 'Secondary', 'order' => 2, 'is_active' => true]);
        $parameters = [
            'stage_id' => $stage->id,
            'return_to' => 'dashboard.stages.show',
            'return_stage_id' => $conflictingStage->id,
        ];

        $this->actingAs($user)
            ->get(route('dashboard.grades.create', $parameters))
            ->assertOk()
            ->assertViewHas('selectedStage', fn ($selected) => $selected->is($stage))
            ->assertViewHas('returnStage', fn ($returnStage) => $returnStage->is($stage))
            ->assertSee('name="stage_id" value="'.$stage->id.'"', false);

        $this->actingAs($user)
            ->post(route('dashboard.grades.store'), $parameters + ['name' => 'Grade 1'])
            ->assertRedirect(route('dashboard.stages.show', $stage));

        $this->assertDatabaseHas('grades', ['stage_id' => $stage->id, 'name' => 'Grade 1']);
    }

    public function test_invalid_scoped_stage_is_rejected(): void
    {
        $this->actingAs($this->authorizedUser())
            ->get(route('dashboard.grades.create', ['stage_id' => 999999]))
            ->assertNotFound();
    }

    public function test_scoped_edit_uses_grades_actual_stage_despite_conflicting_return_stage(): void
    {
        $user = $this->authorizedUser();
        $stage = $this->makeStage();
        $conflictingStage = Stage::create(['name' => 'Secondary', 'order' => 2, 'is_active' => true]);
        $grade = Grade::create(['stage_id' => $stage->id, 'name' => 'Grade 1']);

        $this->actingAs($user)
            ->get(route('dashboard.grades.edit', [
                'grade' => $grade,
                'return_to' => 'dashboard.stages.show',
                'return_stage_id' => $conflictingStage->id,
            ]))
            ->assertOk()
            ->assertViewHas('returnStage', fn ($returnStage) => $returnStage->is($stage));
    }

    public function test_scoped_update_uses_grades_final_actual_stage_despite_conflicting_return_stage(): void
    {
        $user = $this->authorizedUser();
        $originalStage = $this->makeStage();
        $finalStage = Stage::create(['name' => 'Secondary', 'order' => 2, 'is_active' => true]);
        $grade = Grade::create(['stage_id' => $originalStage->id, 'name' => 'Grade 1']);

        $this->actingAs($user)
            ->put(route('dashboard.grades.update', $grade), [
                'stage_id' => $finalStage->id,
                'name' => 'Grade 1 updated',
                'return_to' => 'dashboard.stages.show',
                'return_stage_id' => $originalStage->id,
            ])
            ->assertRedirect(route('dashboard.stages.show', $finalStage));
    }

    public function test_scoped_delete_returns_to_structure_and_external_return_is_ignored(): void
    {
        $user = $this->authorizedUser();
        $stage = $this->makeStage();
        $conflictingStage = Stage::create(['name' => 'Secondary', 'order' => 2, 'is_active' => true]);
        $grade = Grade::create(['stage_id' => $stage->id, 'name' => 'Grade 1']);

        $this->actingAs($user)
            ->delete(route('dashboard.grades.destroy', $grade), [
                'return_to' => 'dashboard.stages.show',
                'return_stage_id' => $conflictingStage->id,
            ])
            ->assertRedirect(route('dashboard.stages.show', $stage));

        $otherGrade = Grade::create(['stage_id' => $stage->id, 'name' => 'Grade 2']);
        $this->actingAs($user)
            ->put(route('dashboard.grades.update', $otherGrade), [
                'stage_id' => $stage->id,
                'name' => 'Grade 2 updated',
                'return_to' => 'https://evil.example',
                'return_stage_id' => $stage->id,
            ])
            ->assertRedirect(route('dashboard.grades.index'));
    }
}
