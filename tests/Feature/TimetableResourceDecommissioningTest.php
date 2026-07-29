<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassResource;
use App\Filament\Resources\ClassResource\Pages\ListClasses;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Batch 7 / Deprecated Timetable UI Removal
 * (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md §6): the deprecated
 * TimetableResource (previously only hidden from navigation in Batch 6)
 * has now been fully removed — class, pages, and dead scaffold files
 * (Schemas/TimetableForm.php, Tables/TimetablesTable.php). The canonical
 * timetable UI remains ClassResource -> TimetableGrid, untouched by this
 * batch. TimetableController and its dashboard/timetable/* routes are
 * also untouched — this batch only concerns the Filament resource.
 */
class TimetableResourceDecommissioningTest extends TestCase
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

    public function test_the_deprecated_resource_class_no_longer_exists(): void
    {
        $this->assertFalse(class_exists(\App\Filament\Resources\Timetables\TimetableResource::class));
    }

    public function test_the_former_filament_route_names_are_no_longer_registered(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter();

        $this->assertFalse($names->contains('filament.admin.resources.timetables.index'));
        $this->assertFalse($names->contains('filament.admin.resources.timetables.create'));
        $this->assertFalse($names->contains('filament.admin.resources.timetables.edit'));

        $this->assertFalse($names->contains(fn ($name) => str_starts_with($name, 'filament.admin.resources.timetables.')));
    }

    public function test_direct_access_to_the_former_filament_timetable_url_returns_404(): void
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole('reception');

        $this->actingAs($user)
            ->get('/admin/timetables')
            ->assertNotFound();

        $this->actingAs($user)
            ->get('/admin/timetables/create')
            ->assertNotFound();
    }

    public function test_the_canonical_timetable_action_remains_visible_and_generates_the_correct_url_for_view_timetable(): void
    {
        $user = $this->makeUserWithPermissions(['view timetable']);
        $class = $this->makeClass();

        Livewire::actingAs($user)
            ->test(ListClasses::class)
            ->assertTableActionVisible('timetable', $class);

        $this->actingAs($user);

        $expectedUrl = route('filament.admin.resources.classes.timetable', ['record' => $class->id]);

        $this->assertSame($expectedUrl, ClassResource::getUrl('timetable', ['record' => $class]));
    }

    public function test_manage_timetable_still_implies_visibility_through_the_existing_batch_5_behavior(): void
    {
        // manage timetable implies view timetable (Batch 4/5) — the row
        // action must not require a separate view timetable grant. This
        // batch does not touch that behavior; asserted here only to prove
        // the removal of TimetableResource left it intact.
        $user = $this->makeUserWithPermissions(['manage timetable']);
        $class = $this->makeClass();

        Livewire::actingAs($user)
            ->test(ListClasses::class)
            ->assertTableActionVisible('timetable', $class);
    }
}
