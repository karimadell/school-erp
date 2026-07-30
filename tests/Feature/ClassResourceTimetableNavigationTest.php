<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassResource;
use App\Filament\Resources\ClassResource\Pages\ListClasses;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Batch 5 / Timetable Navigation (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
 * the 'timetable' row action on ClassResource's list — the only
 * discoverable link to the canonical TimetableGrid page, since a
 * per-class sub-page can never have its own top-level navigation item.
 * Visibility mirrors TimetableGrid::canAccess()/mount() exactly (Batch 4).
 * Does not touch TimetableGrid::mount() itself, so the pre-existing,
 * unrelated Period::orderBy('order') bug (see other Timetable test
 * files) is never reached here.
 */
class ClassResourceTimetableNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Primary ' . uniqid()]);
        $grade = Grade::create(['name' => 'Grade ' . uniqid(), 'stage_id' => $stage->id]);

        return SchoolClass::create(['grade_id' => $grade->id, 'code' => 'C-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);
    }

    protected function makeUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    public function test_a_user_with_no_timetable_permission_does_not_see_the_row_action(): void
    {
        $user = User::factory()->create();
        $class = $this->makeClass();

        Livewire::actingAs($user)
            ->test(ListClasses::class)
            ->assertTableActionHidden('timetable', $class);
    }

    public function test_a_user_with_view_timetable_sees_the_row_action(): void
    {
        $user = $this->makeUserWithPermissions(['view timetable']);
        $class = $this->makeClass();

        Livewire::actingAs($user)
            ->test(ListClasses::class)
            ->assertTableActionVisible('timetable', $class);
    }

    public function test_a_user_with_manage_timetable_only_also_sees_the_row_action(): void
    {
        // manage timetable implies view timetable (Batch 4) — the row
        // action must not require a separate view timetable grant.
        $user = $this->makeUserWithPermissions(['manage timetable']);
        $class = $this->makeClass();

        Livewire::actingAs($user)
            ->test(ListClasses::class)
            ->assertTableActionVisible('timetable', $class);
    }

    public function test_the_generated_url_matches_the_canonical_timetable_grid_route(): void
    {
        // Derived independently via Laravel's own route() helper against
        // TimetableGrid's actual registered route name — not by calling
        // ClassResource::getUrl() again, which would just be circular.
        $user = $this->makeUserWithPermissions(['manage timetable']);
        $class = $this->makeClass();

        $this->actingAs($user);

        $expectedUrl = route('filament.admin.resources.classes.timetable', ['record' => $class->id]);

        $this->assertSame($expectedUrl, ClassResource::getUrl('timetable', ['record' => $class]));
    }
}
