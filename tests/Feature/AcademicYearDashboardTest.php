<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Custom Application Shell Migration — Batch 1: classic /dashboard routes
 * for Academic Years, reusing AcademicYear + AcademicYearPolicy (same
 * policy the Filament resource uses, exercised in AcademicYearTest).
 * These tests only cover the new HTTP surface; the model's
 * exclusive-active-year business rule is already covered there.
 */
class AcademicYearDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithRole(string $role): User
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function makeYear(array $overrides = []): AcademicYear
    {
        return AcademicYear::create(array_merge([
            'name' => '2026 / 2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-05-31',
            'is_active' => false,
        ], $overrides));
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization — role without 'manage academic years'
    |--------------------------------------------------------------------------
    */

    public function test_a_role_without_manage_academic_years_cannot_view_the_index(): void
    {
        $user = $this->userWithRole('accountant');

        $this->actingAs($user)
            ->get(route('dashboard.academic-years.index'))
            ->assertForbidden();
    }

    public function test_a_role_without_manage_academic_years_cannot_view_the_create_form(): void
    {
        $user = $this->userWithRole('accountant');

        $this->actingAs($user)
            ->get(route('dashboard.academic-years.create'))
            ->assertForbidden();
    }

    public function test_a_role_without_manage_academic_years_cannot_store(): void
    {
        $user = $this->userWithRole('accountant');

        $this->actingAs($user)
            ->post(route('dashboard.academic-years.store'), [
                'name' => '2026 / 2027',
                'start_date' => '2026-09-01',
                'end_date' => '2027-05-31',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('academic_years', ['name' => '2026 / 2027']);
    }

    public function test_a_role_without_manage_academic_years_cannot_update(): void
    {
        $user = $this->userWithRole('accountant');
        $year = $this->makeYear();

        $this->actingAs($user)
            ->put(route('dashboard.academic-years.update', $year), [
                'name' => 'Renamed',
                'start_date' => '2026-09-01',
                'end_date' => '2027-05-31',
            ])
            ->assertForbidden();

        $this->assertSame('2026 / 2027', $year->fresh()->name);
    }

    public function test_a_role_without_manage_academic_years_cannot_delete(): void
    {
        $user = $this->userWithRole('accountant');
        $year = $this->makeYear();

        $this->actingAs($user)
            ->delete(route('dashboard.academic-years.destroy', $year))
            ->assertForbidden();

        $this->assertDatabaseHas('academic_years', ['id' => $year->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization — roles with 'manage academic years'
    |--------------------------------------------------------------------------
    */

    public function test_school_admin_can_view_the_index(): void
    {
        $user = $this->userWithRole('school-admin');

        $this->actingAs($user)
            ->get(route('dashboard.academic-years.index'))
            ->assertSuccessful();
    }

    public function test_principal_can_view_the_index(): void
    {
        $user = $this->userWithRole('principal');

        $this->actingAs($user)
            ->get(route('dashboard.academic-years.index'))
            ->assertSuccessful();
    }

    /*
    |--------------------------------------------------------------------------
    | Super Admin bypass
    |--------------------------------------------------------------------------
    */

    public function test_super_admin_can_manage_academic_years_end_to_end(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('dashboard.academic-years.index'))
            ->assertSuccessful();

        $this->actingAs($admin)
            ->post(route('dashboard.academic-years.store'), [
                'name' => '2028 / 2029',
                'start_date' => '2028-09-01',
                'end_date' => '2029-05-31',
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.academic-years.index'));

        $year = AcademicYear::where('name', '2028 / 2029')->firstOrFail();
        $this->assertTrue($year->is_active);

        $this->actingAs($admin)
            ->put(route('dashboard.academic-years.update', $year), [
                'name' => '2028 / 2029 (revised)',
                'start_date' => '2028-09-01',
                'end_date' => '2029-06-15',
            ])
            ->assertRedirect(route('dashboard.academic-years.index'));

        $this->assertSame('2028 / 2029 (revised)', $year->fresh()->name);

        $year->update(['is_active' => false]);

        $this->actingAs($admin)
            ->delete(route('dashboard.academic-years.destroy', $year))
            ->assertRedirect(route('dashboard.academic-years.index'));

        $this->assertDatabaseMissing('academic_years', ['id' => $year->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    public function test_store_requires_name_start_date_and_end_date(): void
    {
        $user = $this->userWithRole('school-admin');

        $this->actingAs($user)
            ->post(route('dashboard.academic-years.store'), [])
            ->assertSessionHasErrors(['name', 'start_date', 'end_date']);

        $this->assertDatabaseCount('academic_years', 0);
    }

    public function test_update_requires_name_start_date_and_end_date(): void
    {
        $user = $this->userWithRole('school-admin');
        $year = $this->makeYear();

        $this->actingAs($user)
            ->put(route('dashboard.academic-years.update', $year), [
                'name' => '',
                'start_date' => '',
                'end_date' => '',
            ])
            ->assertSessionHasErrors(['name', 'start_date', 'end_date']);
    }

    /*
    |--------------------------------------------------------------------------
    | Create / update / delete behavior
    |--------------------------------------------------------------------------
    */

    public function test_school_admin_can_create_an_academic_year(): void
    {
        $user = $this->userWithRole('school-admin');

        $this->actingAs($user)
            ->post(route('dashboard.academic-years.store'), [
                'name' => '2026 / 2027',
                'start_date' => '2026-09-01',
                'end_date' => '2027-05-31',
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.academic-years.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('academic_years', [
            'name' => '2026 / 2027',
            'is_active' => true,
        ]);
    }

    public function test_creating_an_active_year_deactivates_the_previous_active_year(): void
    {
        $user = $this->userWithRole('school-admin');
        $first = $this->makeYear(['is_active' => true]);

        $this->actingAs($user)->post(route('dashboard.academic-years.store'), [
            'name' => '2027 / 2028',
            'start_date' => '2027-09-01',
            'end_date' => '2028-05-31',
            'is_active' => '1',
        ]);

        $this->assertFalse($first->fresh()->is_active);
        $this->assertSame(1, AcademicYear::where('is_active', true)->count());
    }

    public function test_school_admin_can_update_an_academic_year(): void
    {
        $user = $this->userWithRole('school-admin');
        $year = $this->makeYear();

        $this->actingAs($user)
            ->put(route('dashboard.academic-years.update', $year), [
                'name' => '2026 / 2027 (updated)',
                'start_date' => '2026-09-01',
                'end_date' => '2027-06-30',
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.academic-years.index'))
            ->assertSessionHas('success');

        $fresh = $year->fresh();
        $this->assertSame('2026 / 2027 (updated)', $fresh->name);
        $this->assertTrue($fresh->is_active);
    }

    public function test_school_admin_can_delete_an_academic_year(): void
    {
        $user = $this->userWithRole('school-admin');
        $year = $this->makeYear();

        $this->actingAs($user)
            ->delete(route('dashboard.academic-years.destroy', $year))
            ->assertRedirect(route('dashboard.academic-years.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('academic_years', ['id' => $year->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Rendering — empty state, list content, pagination
    |--------------------------------------------------------------------------
    */

    public function test_index_shows_empty_state_when_there_are_no_years(): void
    {
        $user = $this->userWithRole('school-admin');

        $this->actingAs($user)
            ->get(route('dashboard.academic-years.index'))
            ->assertSuccessful()
            ->assertSee(__('academic_years.no_data'));
    }

    public function test_index_lists_existing_years(): void
    {
        $user = $this->userWithRole('school-admin');
        $this->makeYear(['name' => '2026 / 2027']);

        $this->actingAs($user)
            ->get(route('dashboard.academic-years.index'))
            ->assertSuccessful()
            ->assertSee('2026 / 2027');
    }

    public function test_index_paginates_beyond_fifteen_years(): void
    {
        $user = $this->userWithRole('school-admin');

        for ($i = 0; $i < 16; $i++) {
            $this->makeYear([
                'name' => "Year {$i}",
                'start_date' => sprintf('%d-09-01', 2000 + $i),
                'end_date' => sprintf('%d-05-31', 2001 + $i),
            ]);
        }

        $response = $this->actingAs($user)->get(route('dashboard.academic-years.index'));

        $response->assertSuccessful();
        $this->assertTrue($response->viewData('academicYears')->hasPages());
    }

    public function test_edit_form_pre_fills_existing_values(): void
    {
        $user = $this->userWithRole('school-admin');
        $year = $this->makeYear(['name' => '2026 / 2027']);

        $this->actingAs($user)
            ->get(route('dashboard.academic-years.edit', $year))
            ->assertSuccessful()
            ->assertSee('2026 / 2027');
    }

    /*
    |--------------------------------------------------------------------------
    | Sidebar repoint
    |--------------------------------------------------------------------------
    */

    public function test_dashboard_sidebar_links_academic_years_to_the_classic_route_not_filament(): void
    {
        $user = $this->userWithRole('school-admin');

        $response = $this->actingAs($user)->get(route('dashboard.index'));

        $response->assertSuccessful();
        $response->assertSee(route('dashboard.academic-years.index'), false);
    }
}
