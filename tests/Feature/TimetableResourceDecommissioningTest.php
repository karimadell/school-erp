<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassResource\Pages\ListClasses;
use App\Filament\Resources\Timetables\Pages\ListTimetables;
use App\Filament\Resources\Timetables\TimetableResource;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Batch 6 / Deprecated Timetable UI Decommissioning
 * (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md): the deprecated
 * TimetableResource is hidden from Filament navigation only. Its routes
 * remain registered and accessible under the exact same conditions as
 * before this batch — TimetableResource never had a canAccess() override
 * or policy, so access was (and still is) gated purely by
 * User::canAccessPanel('admin'), with no timetable-specific permission
 * required. This is proven with the 'reception' role, which can access
 * the admin panel but holds neither 'view timetable' nor
 * 'manage timetable' (database/seeders/RolesAndPermissionsSeeder.php) —
 * if this batch had silently added a permission gate, this test would
 * fail.
 */
class TimetableResourceDecommissioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_deprecated_resource_is_hidden_from_navigation(): void
    {
        $this->assertFalse(TimetableResource::shouldRegisterNavigation());
    }

    public function test_the_deprecated_resource_route_remains_accessible_under_its_prior_access_conditions(): void
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole('reception');

        $this->assertFalse($user->can('view timetable'));
        $this->assertFalse($user->can('manage timetable'));

        // ListTimetables has a pre-existing, unrelated bug (predates Batch 6,
        // present at HEAD before this batch's diff): its table filter
        // references a `class` relationship that doesn't exist on the
        // Timetable model (only `schoolClass` does), so rendering always
        // throws — out of scope for this batch to fix. Filament's
        // authorizeAccess() hook is a no-op here (no TimetablePolicy exists),
        // so reaching that render-time failure — rather than a 403/Gate
        // denial — is proof this batch did not introduce a permission gate.
        try {
            Livewire::actingAs($user)->test(ListTimetables::class);
            $this->fail('Expected the pre-existing relationship bug to throw during render.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString(
                'The relationship [class] does not exist on the model [App\Models\Timetable].',
                $e->getMessage(),
            );
        }
    }

    public function test_the_canonical_class_timetable_row_action_remains_unaffected(): void
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'manage timetable']);
        $user->givePermissionTo('manage timetable');

        $stage = Stage::create(['name' => 'Primary ' . uniqid()]);
        $grade = Grade::create(['name' => 'Grade ' . uniqid(), 'stage_id' => $stage->id]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'C-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);

        Livewire::actingAs($user)
            ->test(ListClasses::class)
            ->assertTableActionVisible('timetable', $class);
    }
}
