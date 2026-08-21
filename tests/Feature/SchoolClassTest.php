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
        $response->assertSee(__('classes.empty_heading'));
    }

    public function test_index_renders_class_details_and_correct_timetable_link(): void
    {
        $user = $this->portalUser();
        Permission::findOrCreate('view timetable', 'web');
        $user->givePermissionTo('view timetable');
        $grade = $this->makeGrade();
        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'UAT-5A',
            'name_ar' => 'UAT 5',
            'name_ru' => 'UAT — 5 класс',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.classes.index'))
            ->assertOk()
            ->assertSee('UAT — 5 класс')
            ->assertSee('UAT-5A')
            ->assertSee(__('classes.active'))
            ->assertSee(route('dashboard.classes.timetable', $class), false);
    }

    public function test_index_keeps_management_actions_permission_gated(): void
    {
        $grade = $this->makeGrade();
        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'A',
            'name_ar' => 'A',
            'name_ru' => 'Класс A',
            'is_active' => true,
        ]);

        $this->actingAs($this->portalUser())
            ->get(route('dashboard.classes.index'))
            ->assertOk()
            ->assertDontSee(route('dashboard.classes.create'), false)
            ->assertDontSee(route('dashboard.classes.edit', $class), false)
            ->assertDontSee(route('dashboard.classes.destroy', $class), false);

        $this->actingAs($this->authorizedUser())
            ->get(route('dashboard.classes.index'))
            ->assertOk()
            ->assertSee(route('dashboard.classes.create'), false)
            ->assertSee(route('dashboard.classes.edit', $class), false)
            ->assertSee(route('dashboard.classes.destroy', $class), false);
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

    /**
     * UAT fix: the class list previously used ->latest() (insertion order),
     * which produced sequences like 11, 10, 8, 7, 6, 4, 3, 2, 0, 9, 5, 1.
     * It must instead follow Grade.level — the same canonical numeric field
     * Grade::scopeOrdered() already uses for the create/edit grade
     * dropdowns — not a lexicographic sort on the class code (which would
     * put "10A" before "2A").
     */
    public function test_classes_render_in_natural_academic_order_not_insertion_or_lexicographic_order(): void
    {
        $user = $this->portalUser();
        $stage = Stage::create(['name' => 'Stage ' . uniqid()]);

        // Deliberately create grades/classes out of order, with a level
        // sequence that would sort wrong both by insertion (id) order and
        // by lexicographic string order (grade "10" before grade "2").
        $levels = [11, 10, 8, 7, 6, 4, 3, 2, 0, 9, 5, 1];
        foreach ($levels as $level) {
            $grade = Grade::create(['name' => "UAT — {$level} класс", 'stage_id' => $stage->id]);
            $grade->forceFill(['level' => $level])->save();

            SchoolClass::create([
                'grade_id' => $grade->id, 'code' => "UAT-{$level}A", 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true,
            ]);
        }

        $response = $this->actingAs($user)->get(route('dashboard.classes.index'));

        $response->assertOk();
        $response->assertViewHas('classes', function ($classes) {
            return $classes->pluck('code')->all() === [
                'UAT-0A', 'UAT-1A', 'UAT-2A', 'UAT-3A', 'UAT-4A', 'UAT-5A',
                'UAT-6A', 'UAT-7A', 'UAT-8A', 'UAT-9A', 'UAT-10A', 'UAT-11A',
            ];
        });
    }

    public function test_parallel_classes_within_the_same_grade_have_deterministic_secondary_order(): void
    {
        $user = $this->portalUser();
        $stage = Stage::create(['name' => 'Stage ' . uniqid()]);
        $grade = Grade::create(['name' => 'UAT — 1 класс', 'stage_id' => $stage->id]);
        $grade->forceFill(['level' => 1])->save();

        // Created out of alphabetical order to prove the secondary sort is
        // real, not accidental insertion order.
        SchoolClass::create(['grade_id' => $grade->id, 'code' => '1B', 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);
        SchoolClass::create(['grade_id' => $grade->id, 'code' => '1A', 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);

        $response = $this->actingAs($user)->get(route('dashboard.classes.index'));

        $response->assertOk();
        $response->assertViewHas('classes', fn ($classes) => $classes->pluck('code')->all() === ['1A', '1B']);
    }

    public function test_classes_with_no_grade_level_sort_after_leveled_grades(): void
    {
        $user = $this->portalUser();
        $stage = Stage::create(['name' => 'Stage ' . uniqid()]);
        $leveled = Grade::create(['name' => 'UAT — 1 класс', 'stage_id' => $stage->id]);
        $leveled->forceFill(['level' => 1])->save();
        $unleveled = Grade::create(['name' => 'Unleveled grade', 'stage_id' => $stage->id]); // level left null

        SchoolClass::create(['grade_id' => $unleveled->id, 'code' => 'U', 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);
        SchoolClass::create(['grade_id' => $leveled->id, 'code' => 'L', 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true]);

        $response = $this->actingAs($user)->get(route('dashboard.classes.index'));

        $response->assertOk();
        $response->assertViewHas('classes', fn ($classes) => $classes->pluck('code')->all() === ['L', 'U']);
    }

    /**
     * UAT hardening: the class list table exposed the raw DB primary key as
     * a visible "ID" column. It must be removed from the markup while the
     * underlying record ids (and natural academic ordering) stay intact.
     */
    public function test_class_list_does_not_display_the_internal_database_id_column(): void
    {
        $user = $this->portalUser();
        $grade = $this->makeGrade();

        $class = SchoolClass::create([
            'grade_id' => $grade->id, 'code' => 'ID-TEST-A', 'name_ar' => 'a', 'name_ru' => 'a', 'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.classes.index'));

        $response->assertOk();
        $response->assertSee($class->code);

        // The removed column's header/body markup must be gone, not just relabeled.
        $response->assertDontSee('<th>' . __('classes.id') . '</th>', false);
        $response->assertDontSee('<td>' . $class->id . '</td>', false);
    }
}
