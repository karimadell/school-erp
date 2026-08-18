<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SchoolClassTest extends TestCase
{
    use RefreshDatabase;

    protected function makeGrade(): Grade
    {
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);

        return Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
    }

    /**
     * Portal-eligible but unprivileged ('reception', active): clears
     * EnsureAdministrativePortalAccess but lacks 'manage classes', so the
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
        Permission::findOrCreate('manage classes', 'web');
        $user->givePermissionTo('manage classes');

        return $user;
    }

    public function test_name_ar_is_mass_assignable(): void
    {
        // Regression test: classes.name_ar is NOT NULL in the database but
        // was missing from SchoolClass::$fillable, so any mass-assignment
        // create/update (as ClassController::store/update perform) either
        // silently dropped it or violated the NOT NULL constraint.
        $grade = $this->makeGrade();

        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'A',
            'name_ar' => 'فصل A',
            'name_ru' => 'Класс A',
            'capacity' => 25,
            'is_active' => true,
        ]);

        $this->assertSame('فصل A', $class->fresh()->name_ar);
    }

    public function test_any_authenticated_user_can_view_the_index(): void
    {
        $user = $this->portalUser();

        $response = $this->actingAs($user)->get(route('dashboard.classes.index'));

        $response->assertOk();
    }

    public function test_unauthorized_user_cannot_open_create_page(): void
    {
        $user = $this->portalUser();

        $response = $this->actingAs($user)->get(route('dashboard.classes.create'));

        $response->assertForbidden();
    }

    public function test_unauthorized_user_cannot_store_a_class(): void
    {
        $user = $this->portalUser();
        $grade = $this->makeGrade();

        $response = $this->actingAs($user)->post(route('dashboard.classes.store'), [
            'grade_id' => $grade->id,
            'code' => 'B',
            'name_ru' => 'Класс B',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('classes', 0);
    }

    public function test_unauthorized_user_cannot_delete_a_class(): void
    {
        $user = $this->authorizedUser();
        $grade = $this->makeGrade();

        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'D',
            'name_ar' => 'فصل D',
            'name_ru' => 'Класс D',
            'capacity' => 25,
            'is_active' => true,
        ]);

        // Revoke permission to confirm destroy is actually gated.
        $user->revokePermissionTo('manage classes');

        $response = $this->actingAs($user)->delete(route('dashboard.classes.destroy', $class));

        $response->assertForbidden();
        $this->assertDatabaseHas('classes', ['id' => $class->id]);
    }

    public function test_class_can_be_created_via_controller(): void
    {
        $user = $this->authorizedUser();
        $grade = $this->makeGrade();

        $response = $this->actingAs($user)->post(route('dashboard.classes.store'), [
            'grade_id' => $grade->id,
            'code' => 'B',
            'name_ru' => 'Класс B',
            'capacity' => 25,
        ]);

        $response->assertRedirect(route('dashboard.classes.index'));

        $class = SchoolClass::where('code', 'B')->firstOrFail();
        $this->assertSame('Класс B', $class->name_ar);
        $this->assertSame('Класс B', $class->name_ru);
    }

    public function test_class_name_ar_can_be_updated_via_controller(): void
    {
        $user = $this->authorizedUser();
        $grade = $this->makeGrade();

        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'C',
            'name_ar' => 'فصل C',
            'name_ru' => 'Класс C',
            'capacity' => 25,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->put(route('dashboard.classes.update', $class), [
            'grade_id' => $grade->id,
            'code' => 'C',
            'name_ru' => 'Класс C Updated',
            'capacity' => 30,
        ]);

        $response->assertRedirect(route('dashboard.classes.index'));

        $this->assertSame('Класс C Updated', $class->fresh()->name_ar);
    }

    public function test_scoped_create_preselects_grade_and_returns_to_structure(): void
    {
        $user = $this->authorizedUser();
        $grade = $this->makeGrade();
        $stage = $grade->stage;
        $conflictingStage = Stage::create(['name' => 'Secondary', 'order' => 2, 'is_active' => true]);
        $parameters = [
            'grade_id' => $grade->id,
            'return_to' => 'dashboard.stages.show',
            'return_stage_id' => $conflictingStage->id,
        ];

        $this->actingAs($user)
            ->get(route('dashboard.classes.create', $parameters))
            ->assertOk()
            ->assertViewHas('selectedGrade', fn ($selected) => $selected->is($grade))
            ->assertViewHas('returnStage', fn ($returnStage) => $returnStage->is($stage))
            ->assertSee('name="grade_id" value="'.$grade->id.'"', false);

        $this->actingAs($user)
            ->post(route('dashboard.classes.store'), $parameters + [
                'code' => 'B',
                'name_ru' => 'Класс B',
            ])
            ->assertRedirect(route('dashboard.stages.show', $stage));

        $this->assertDatabaseHas('classes', ['grade_id' => $grade->id, 'code' => 'B']);
    }

    public function test_invalid_scoped_grade_is_rejected(): void
    {
        $this->actingAs($this->authorizedUser())
            ->get(route('dashboard.classes.create', ['grade_id' => 999999]))
            ->assertNotFound();
    }

    public function test_scoped_edit_uses_class_actual_stage_despite_conflicting_return_stage(): void
    {
        $user = $this->authorizedUser();
        $grade = $this->makeGrade();
        $stage = $grade->stage;
        $conflictingStage = Stage::create(['name' => 'Secondary', 'order' => 2, 'is_active' => true]);
        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'A',
            'name_ar' => 'A',
            'name_ru' => 'A',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.classes.edit', [
                'class' => $class,
                'return_to' => 'dashboard.stages.show',
                'return_stage_id' => $conflictingStage->id,
            ]))
            ->assertOk()
            ->assertViewHas('returnStage', fn ($returnStage) => $returnStage->is($stage));
    }

    public function test_scoped_update_uses_class_final_actual_stage_despite_conflicting_return_stage(): void
    {
        $user = $this->authorizedUser();
        $originalGrade = $this->makeGrade();
        $originalStage = $originalGrade->stage;
        $finalStage = Stage::create(['name' => 'Secondary', 'order' => 2, 'is_active' => true]);
        $finalGrade = Grade::create(['name' => 'Grade 2', 'stage_id' => $finalStage->id]);
        $class = SchoolClass::create([
            'grade_id' => $originalGrade->id,
            'code' => 'A',
            'name_ar' => 'A',
            'name_ru' => 'A',
        ]);

        $this->actingAs($user)
            ->put(route('dashboard.classes.update', $class), [
                'grade_id' => $finalGrade->id,
                'code' => 'A',
                'name_ru' => 'A updated',
                'return_to' => 'dashboard.stages.show',
                'return_stage_id' => $originalStage->id,
            ])
            ->assertRedirect(route('dashboard.stages.show', $finalStage));
    }

    public function test_scoped_delete_returns_to_structure_and_external_return_is_ignored(): void
    {
        $user = $this->authorizedUser();
        $grade = $this->makeGrade();
        $stage = $grade->stage;
        $conflictingStage = Stage::create(['name' => 'Secondary', 'order' => 2, 'is_active' => true]);
        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'A',
            'name_ar' => 'A',
            'name_ru' => 'A',
        ]);

        $this->actingAs($user)
            ->delete(route('dashboard.classes.destroy', $class), [
                'return_to' => 'dashboard.stages.show',
                'return_stage_id' => $conflictingStage->id,
            ])
            ->assertRedirect(route('dashboard.stages.show', $stage));

        $otherClass = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'B',
            'name_ar' => 'B',
            'name_ru' => 'B',
        ]);
        $this->actingAs($user)
            ->put(route('dashboard.classes.update', $otherClass), [
                'grade_id' => $grade->id,
                'code' => 'B',
                'name_ru' => 'B updated',
                'return_to' => '//evil.example',
                'return_stage_id' => $stage->id,
            ])
            ->assertRedirect(route('dashboard.classes.index'));
    }
}
