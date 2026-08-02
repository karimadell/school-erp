<?php

namespace Tests\Feature;

use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Fees\Pages\ListFees;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Batch 6: proves the approved role -> permission matrix end to end —
 * both the seeded grants themselves and their real enforcement (panel
 * access, Filament resource policies, dashboard controller middleware).
 * Deny by default: every assertion below has a matching "this role must
 * NOT have this" counterpart, not just "this role can".
 */
class AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithRole(string $role): User
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | Panel access (canAccessPanel)
    |--------------------------------------------------------------------------
    */

    public function test_a_user_with_no_role_cannot_access_either_panel(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')));
        $this->assertFalse($user->canAccessPanel(\Filament\Facades\Filament::getPanel('teacher')));
    }

    public function test_every_admin_side_role_can_access_the_admin_panel(): void
    {
        foreach (['admin', 'school-admin', 'accountant', 'reception', 'principal'] as $role) {
            $user = $this->userWithRole($role);

            $this->assertTrue(
                $user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')),
                "Role [{$role}] should be able to access the admin panel."
            );
        }
    }

    public function test_teacher_role_cannot_access_the_admin_panel(): void
    {
        $user = $this->userWithRole('teacher');

        $this->assertFalse($user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')));
    }

    public function test_only_an_active_linked_teacher_can_access_the_teacher_panel(): void
    {
        $teacherPanel = \Filament\Facades\Filament::getPanel('teacher');

        $teacher = $this->userWithRole('teacher');
        \App\Models\Teacher::create([
            'user_id' => $teacher->id,
            'first_name' => 'Anna',
            'last_name' => 'Ivanova',
            'is_active' => true,
        ]);

        $this->assertTrue($teacher->canAccessPanel($teacherPanel));
        $this->assertFalse($this->userWithRole('admin')->canAccessPanel($teacherPanel));

        foreach (['school-admin', 'accountant', 'reception', 'principal'] as $role) {
            $this->assertFalse(
                $this->userWithRole($role)->canAccessPanel($teacherPanel),
                "Role [{$role}] should NOT be able to access the teacher panel."
            );
        }
    }

    public function test_disabled_and_unlinked_users_cannot_access_panels(): void
    {
        $admin = $this->userWithRole('admin');
        $admin->update(['is_active' => false]);

        $teacher = $this->userWithRole('teacher');

        $this->assertFalse($admin->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')));
        $this->assertFalse($teacher->canAccessPanel(\Filament\Facades\Filament::getPanel('teacher')));
    }

    /*
    |--------------------------------------------------------------------------
    | Seeded permission matrix — spot-check the key differentiators
    |--------------------------------------------------------------------------
    */

    public function test_super_admin_has_every_permission_including_system_configuration(): void
    {
        $user = $this->userWithRole('admin');

        foreach (['manage users', 'manage roles', 'manage permissions', 'override service prices', 'manage fees'] as $permission) {
            $this->assertTrue($user->can($permission), "admin should have [{$permission}].");
        }
    }

    public function test_school_admin_has_full_operational_access_but_no_system_configuration(): void
    {
        $user = $this->userWithRole('school-admin');

        foreach (['manage fees', 'manage invoices', 'override service prices', 'delete students', 'manage academic years'] as $permission) {
            $this->assertTrue($user->can($permission), "school-admin should have [{$permission}].");
        }

        foreach (['manage users', 'manage roles', 'manage permissions'] as $permission) {
            $this->assertFalse($user->can($permission), "school-admin should NOT have [{$permission}].");
        }
    }

    public function test_accountant_has_full_finance_access_read_only_students_no_academic_or_override(): void
    {
        $user = $this->userWithRole('accountant');

        foreach (['manage fees', 'manage fee prices', 'manage invoices', 'manage expenses', 'manage student service subscriptions'] as $permission) {
            $this->assertTrue($user->can($permission), "accountant should have [{$permission}].");
        }

        $this->assertTrue($user->can('view students'));
        $this->assertTrue($user->can('view enrollments'));

        foreach (['create students', 'update students', 'delete students', 'create enrollments'] as $permission) {
            $this->assertFalse($user->can($permission), "accountant should NOT have [{$permission}] (read-only).");
        }

        foreach (['manage stages', 'manage grades', 'manage classes', 'manage subjects', 'manage teachers', 'override service prices', 'manage roles'] as $permission) {
            $this->assertFalse($user->can($permission), "accountant should NOT have [{$permission}].");
        }
    }

    public function test_reception_can_create_view_update_students_and_enrollments_but_not_delete(): void
    {
        $user = $this->userWithRole('reception');

        foreach (['view students', 'create students', 'update students', 'view enrollments', 'create enrollments', 'update enrollments'] as $permission) {
            $this->assertTrue($user->can($permission), "reception should have [{$permission}].");
        }

        foreach (['delete students', 'delete enrollments'] as $permission) {
            $this->assertFalse($user->can($permission), "reception should NOT have [{$permission}].");
        }
    }

    public function test_reception_has_read_only_finance_and_no_sensitive_financial_permissions(): void
    {
        $user = $this->userWithRole('reception');

        $this->assertTrue($user->can('view invoices'));
        $this->assertTrue($user->can('view student balances'));

        foreach (['manage invoices', 'manage fees', 'manage fee prices', 'manage expenses', 'override service prices', 'manage roles', 'manage permissions'] as $permission) {
            $this->assertFalse($user->can($permission), "reception should NOT have [{$permission}].");
        }
    }

    public function test_teacher_role_has_zero_admin_panel_permissions(): void
    {
        $user = $this->userWithRole('teacher');

        foreach ([
            'view students', 'manage fees', 'manage invoices', 'view invoices',
            'manage stages', 'manage teachers', 'override service prices', 'manage users',
        ] as $permission) {
            $this->assertFalse($user->can($permission), "teacher should NOT have [{$permission}].");
        }
    }

    public function test_principal_has_full_academic_permissions_and_read_only_finance_with_no_fee_price_editing(): void
    {
        $user = $this->userWithRole('principal');

        foreach (['manage subjects', 'manage stages', 'manage grades', 'manage classes', 'manage academic years'] as $permission) {
            $this->assertTrue($user->can($permission), "principal should have [{$permission}].");
        }

        $this->assertTrue($user->can('view invoices'));
        $this->assertTrue($user->can('view cash reports'));

        foreach (['manage fee prices', 'manage fees', 'manage invoices', 'manage expenses', 'override service prices'] as $permission) {
            $this->assertFalse($user->can($permission), "principal should NOT have [{$permission}].");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Filament resource Policies — real enforcement, not just seeded grants
    |--------------------------------------------------------------------------
    */

    public function test_reception_cannot_open_the_fees_filament_resource(): void
    {
        $user = $this->userWithRole('reception');

        Livewire::actingAs($user)->test(ListFees::class)->assertForbidden();
    }

    public function test_accountant_can_open_the_fees_filament_resource(): void
    {
        $user = $this->userWithRole('accountant');

        Livewire::actingAs($user)->test(ListFees::class)->assertSuccessful();
    }

    public function test_reception_cannot_open_the_expenses_filament_resource(): void
    {
        $user = $this->userWithRole('reception');

        Livewire::actingAs($user)->test(ListExpenses::class)->assertForbidden();
    }

    public function test_accountant_can_open_the_expenses_filament_resource(): void
    {
        $user = $this->userWithRole('accountant');

        Livewire::actingAs($user)->test(ListExpenses::class)->assertSuccessful();
    }

    /*
    |--------------------------------------------------------------------------
    | Dashboard controller middleware — Invoice/Fee/FeePrice
    |--------------------------------------------------------------------------
    */

    public function test_reception_can_view_but_not_create_invoices(): void
    {
        $user = $this->userWithRole('reception');

        $this->actingAs($user)->get(route('dashboard.invoices.index'))->assertOk();
        $this->actingAs($user)->get(route('dashboard.invoices.create'))->assertForbidden();
    }

    public function test_accountant_can_manage_fee_prices_but_principal_cannot(): void
    {
        $accountant = $this->userWithRole('accountant');
        $principal = $this->userWithRole('principal');

        $this->actingAs($accountant)->get(route('dashboard.fee-prices.index'))->assertOk();
        $this->actingAs($principal)->get(route('dashboard.fee-prices.index'))->assertForbidden();
    }
}
