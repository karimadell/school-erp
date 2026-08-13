<?php

namespace Tests\Feature\Leadership;

use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PrincipalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
    }

    protected function principal(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('principal');

        return $user;
    }

    public function test_principal_enters_admin_surfaces_but_not_teacher_panel(): void
    {
        $principal = $this->principal();

        $this->actingAs($principal)->get('/admin')->assertOk();
        $this->actingAs($principal)->get('/dashboard')->assertOk();
        $this->actingAs($principal)->get('/teacher')->assertForbidden();
    }

    public function test_principal_has_complete_operational_visibility(): void
    {
        $principal = $this->principal();

        foreach ([
            'view students', 'create students', 'update students', 'delete students',
            'manage teachers', 'view enrollments', 'manage subjects', 'manage stages',
            'manage grades', 'manage classes', 'manage academic years',
            'manage journal entries', 'view invoices',
            'manage invoices', 'manage fees', 'manage fee prices', 'manage expenses',
            'manage student service subscriptions', 'override service prices',
            'view student balances', 'manage cash', 'view cash reports', 'view audit logs',
            'manage users', 'manage roles',
        ] as $permission) {
            $this->assertTrue($principal->can($permission), "Principal lacks [{$permission}].");
        }

        // Curriculum and Timetable are admin-only configuration (ADR-016) —
        // withheld from principal exactly as from school-admin, alongside the
        // protected system permissions.
        foreach ([
            'manage permissions', 'unlock historical academic year',
            'manage curriculum', 'view timetable', 'manage timetable',
        ] as $permission) {
            $this->assertFalse($principal->can($permission), "Principal should NOT have [{$permission}].");
        }
    }

    public function test_principal_can_review_users_roles_and_audit_logs(): void
    {
        $principal = $this->principal();

        Livewire::actingAs($principal)->test(ListUsers::class)->assertSuccessful();
        Livewire::actingAs($principal)->test(ListRoles::class)->assertSuccessful();
        $this->actingAs($principal)->get('/dashboard/admin/users')->assertOk();
        $this->actingAs($principal)->get('/dashboard/admin/roles')->assertOk();
        $this->actingAs($principal)->get('/dashboard/admin/audit-logs')->assertOk();
    }
}
