<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\PhysicalClassroom;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 dashboard migration: dashboard-native counterpart to
 * App\Filament\Resources\Classrooms\ClassroomResource (model:
 * PhysicalClassroom / table `classrooms` — distinct from the legacy,
 * orphaned App\Models\ClassRoom). Reuses the exact same model and its
 * validation — no business logic is duplicated here.
 */
class DashboardClassroomTest extends TestCase
{
    use RefreshDatabase;

    protected function adminUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    protected function schoolAdminUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('school-admin');

        return $user;
    }

    protected function year(): AcademicYear
    {
        return AcademicYear::create([
            'name' => 'Year ' . uniqid(), 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
    }

    /** Holds 'view timetable' only — never 'manage timetable'. */
    protected function viewOnlyUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('school-admin');
        $user->givePermissionTo('view timetable');

        return $user;
    }

    protected function classroom(AcademicYear $year, array $overrides = []): PhysicalClassroom
    {
        return PhysicalClassroom::create(array_merge([
            'academic_year_id' => $year->id, 'code' => 'A-' . uniqid(), 'name' => 'X',
            'capacity' => 10, 'room_type' => PhysicalClassroom::TYPE_CLASSROOM, 'is_active' => true,
        ], $overrides));
    }

    public function test_sidebar_classrooms_link_stays_inside_dashboard_not_admin(): void
    {
        $admin = $this->adminUser();

        $html = (string) $this->actingAs($admin)->view('layouts.partials.shell-sidebar');

        $this->assertStringContainsString(route('dashboard.classrooms.index'), $html);
        $this->assertStringNotContainsString('/admin/classrooms', $html);
    }

    public function test_authorized_administrative_user_can_view_the_list(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->get(route('dashboard.classrooms.index'))->assertOk();
    }

    public function test_direct_access_is_forbidden_without_timetable_permission(): void
    {
        $schoolAdmin = $this->schoolAdminUser();

        $this->actingAs($schoolAdmin)->get(route('dashboard.classrooms.index'))->assertForbidden();
    }

    public function test_guest_cannot_access_classrooms(): void
    {
        $this->get(route('dashboard.classrooms.index'))->assertRedirect(route('login'));
    }

    public function test_admin_can_create_a_classroom(): void
    {
        $admin = $this->adminUser();
        $year = $this->year();

        $response = $this->actingAs($admin)->post(route('dashboard.classrooms.store'), [
            'academic_year_id' => $year->id,
            'code' => 'A-101',
            'name' => 'Кабинет физики',
            'capacity' => 30,
            'room_type' => PhysicalClassroom::TYPE_SCIENCE_LAB,
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('dashboard.classrooms.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('classrooms', [
            'academic_year_id' => $year->id,
            'code' => 'A-101',
            'room_type' => PhysicalClassroom::TYPE_SCIENCE_LAB,
        ]);
    }

    public function test_duplicate_code_within_the_same_year_is_rejected_by_the_shared_model_validation(): void
    {
        $admin = $this->adminUser();
        $year = $this->year();
        PhysicalClassroom::create([
            'academic_year_id' => $year->id, 'code' => 'A-101', 'name' => 'X',
            'capacity' => 10, 'room_type' => PhysicalClassroom::TYPE_CLASSROOM, 'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('dashboard.classrooms.store'), [
            'academic_year_id' => $year->id,
            'code' => 'A-101',
            'name' => 'Дубликат',
            'capacity' => 20,
            'room_type' => PhysicalClassroom::TYPE_LABORATORY,
            'is_active' => '1',
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(1, PhysicalClassroom::where('academic_year_id', $year->id)->where('code', 'A-101')->count());
    }

    public function test_there_is_no_delete_route_for_classrooms(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('dashboard.classrooms.destroy'));
    }

    public function test_edit_page_never_leaves_the_dashboard_shell(): void
    {
        $admin = $this->adminUser();
        $year = $this->year();
        $classroom = PhysicalClassroom::create([
            'academic_year_id' => $year->id, 'code' => 'A-101', 'name' => 'X',
            'capacity' => 10, 'room_type' => PhysicalClassroom::TYPE_CLASSROOM, 'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard.classrooms.edit', $classroom));

        $response->assertOk();
        $response->assertSee('data-shell', false);
        $response->assertDontSee('/admin/classrooms', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-UAT Fix 5 — authorization matrix (view timetable, no manage timetable)
    |--------------------------------------------------------------------------
    */

    public function test_view_only_user_is_forbidden_from_every_classroom_mutation_route(): void
    {
        $user = $this->viewOnlyUser();
        $year = $this->year();
        $classroom = $this->classroom($year);

        $this->actingAs($user)->get(route('dashboard.classrooms.create'))->assertForbidden();
        $this->actingAs($user)->post(route('dashboard.classrooms.store'), [
            'academic_year_id' => $year->id, 'code' => 'X-1', 'name' => 'X',
            'capacity' => 10, 'room_type' => PhysicalClassroom::TYPE_CLASSROOM,
        ])->assertForbidden();
        $this->actingAs($user)->get(route('dashboard.classrooms.edit', $classroom))->assertForbidden();
        $this->actingAs($user)->put(route('dashboard.classrooms.update', $classroom), [
            'academic_year_id' => $year->id, 'code' => $classroom->code, 'name' => 'X',
            'capacity' => 10, 'room_type' => PhysicalClassroom::TYPE_CLASSROOM,
        ])->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-UAT Fix 6 — update success/integration + update validation
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_update_a_classroom_and_stays_inside_the_dashboard(): void
    {
        $admin = $this->adminUser();
        $year = $this->year();
        $classroom = $this->classroom($year, ['name' => 'Before', 'capacity' => 10]);

        $response = $this->actingAs($admin)->put(route('dashboard.classrooms.update', $classroom), [
            'academic_year_id' => $year->id,
            'code' => $classroom->code,
            'name' => 'After',
            'capacity' => 25,
            'room_type' => PhysicalClassroom::TYPE_COMPUTER_LAB,
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('dashboard.classrooms.index'));
        $this->assertStringStartsWith(url('/dashboard'), $response->headers->get('Location'));
        $response->assertSessionHas('success');

        $classroom->refresh();
        $this->assertSame('After', $classroom->name);
        $this->assertSame(25, $classroom->capacity);
        $this->assertSame(PhysicalClassroom::TYPE_COMPUTER_LAB, $classroom->room_type);
    }

    public function test_updating_a_classroom_to_a_code_already_used_in_the_same_year_is_rejected(): void
    {
        $admin = $this->adminUser();
        $year = $this->year();
        $this->classroom($year, ['code' => 'TAKEN']);
        $classroom = $this->classroom($year, ['code' => 'FREE']);

        $response = $this->actingAs($admin)->put(route('dashboard.classrooms.update', $classroom), [
            'academic_year_id' => $year->id,
            'code' => 'TAKEN',
            'name' => $classroom->name,
            'capacity' => $classroom->capacity,
            'room_type' => $classroom->room_type,
            'is_active' => '1',
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame('FREE', $classroom->fresh()->code);
    }

    public function test_updating_a_classroom_while_keeping_its_own_code_is_allowed(): void
    {
        $admin = $this->adminUser();
        $year = $this->year();
        $classroom = $this->classroom($year, ['code' => 'SAME']);

        $response = $this->actingAs($admin)->put(route('dashboard.classrooms.update', $classroom), [
            'academic_year_id' => $year->id,
            'code' => 'SAME',
            'name' => 'Renamed',
            'capacity' => $classroom->capacity,
            'room_type' => $classroom->room_type,
            'is_active' => '1',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame('Renamed', $classroom->fresh()->name);
    }
}
