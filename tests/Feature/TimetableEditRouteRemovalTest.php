<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassResource;
use App\Filament\Resources\ClassResource\Pages\ListClasses;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Batch 8 / Deprecated Timetable Edit Flow Removal
 * (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md §6): TimetableController's
 * `edit` route pointed at a controller method that has never existed
 * (confirmed present since the initial repo upload), so every hit fataled
 * with a BadMethodCallException. resources/views/dashboard/timetable/
 * show.blade.php linked to it on every lesson row. This batch removes
 * only that dead route/view/link — every other TimetableController route
 * (index/create/store/update/destroy/move/teachersBySubject/pdf/show)
 * and its lack of a permission gate are explicitly out of scope and left
 * untouched. The canonical timetable UI remains
 * ClassResource -> TimetableGrid, unaffected by this batch.
 */
class TimetableEditRouteRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Primary ' . uniqid()]);
        $grade = Grade::create(['name' => 'Grade ' . uniqid(), 'stage_id' => $stage->id]);

        return SchoolClass::create(['grade_id' => $grade->id, 'code' => 'C-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);
    }

    public function test_the_former_edit_route_name_is_no_longer_registered(): void
    {
        $this->assertFalse(Route::has('dashboard.timetable.edit'));
    }

    public function test_the_former_edit_url_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard/timetable/1/edit')
            ->assertNotFound();
    }

    public function test_the_remaining_timetable_controller_routes_still_resolve_exactly_as_before(): void
    {
        $this->assertTrue(Route::has('dashboard.timetable.index'));
        $this->assertTrue(Route::has('dashboard.timetable.create'));
        $this->assertTrue(Route::has('dashboard.timetable.store'));
        $this->assertTrue(Route::has('dashboard.timetable.update'));
        $this->assertTrue(Route::has('dashboard.timetable.destroy'));
        $this->assertTrue(Route::has('dashboard.timetable.move'));
        $this->assertTrue(Route::has('dashboard.timetable.subject.teachers'));
        $this->assertTrue(Route::has('dashboard.timetable.pdf'));
        $this->assertTrue(Route::has('dashboard.timetable.show'));

        $this->assertSame('/dashboard/timetable', route('dashboard.timetable.index', [], false));
        $this->assertSame('/dashboard/timetable/create', route('dashboard.timetable.create', [], false));
        $this->assertSame('/dashboard/timetable/1', route('dashboard.timetable.update', 1, false));
        $this->assertSame('/dashboard/timetable/1', route('dashboard.timetable.destroy', 1, false));
        $this->assertSame('/dashboard/timetable/1/move', route('dashboard.timetable.move', 1, false));
        $this->assertSame('/dashboard/timetable/subject/1/teachers', route('dashboard.timetable.subject.teachers', 1, false));
        $this->assertSame('/dashboard/timetable/class/1/pdf', route('dashboard.timetable.pdf', 1, false));
        $this->assertSame('/dashboard/timetable/class/1', route('dashboard.timetable.show', 1, false));
    }

    public function test_the_canonical_class_resource_timetable_grid_flow_remains_unaffected(): void
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'view timetable']);
        $user->givePermissionTo('view timetable');

        $class = $this->makeClass();

        Livewire::actingAs($user)
            ->test(ListClasses::class)
            ->assertTableActionVisible('timetable', $class);

        $this->actingAs($user);

        $expectedUrl = route('filament.admin.resources.classes.timetable', ['record' => $class->id]);

        $this->assertSame($expectedUrl, ClassResource::getUrl('timetable', ['record' => $class]));
    }
}
