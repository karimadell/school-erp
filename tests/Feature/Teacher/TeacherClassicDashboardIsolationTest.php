<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherClassicDashboardIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
    }

    protected function teacher(): User
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        Teacher::create([
            'user_id' => $user->id,
            'first_name' => 'Anna',
            'last_name' => 'Ivanova',
            'is_active' => true,
        ]);

        return $user;
    }

    public function test_teacher_is_redirected_before_every_representative_admin_controller_runs(): void
    {
        $this->actingAs($this->teacher());

        foreach ([
            '/dashboard',
            '/dashboard/students',
            '/dashboard/teachers',
            '/dashboard/invoices',
            '/dashboard/attendance',
            '/dashboard/student-grades',
            '/dashboard/admin/users',
            '/dashboard/admin/roles',
            '/salaries',
            '/salaries/create',
        ] as $uri) {
            $this->get($uri)->assertRedirect('/teacher');
        }

        $this->post('/salaries/import')->assertRedirect('/teacher');
        $this->get('/salaries/payslip/999999')->assertRedirect('/teacher');
    }

    public function test_guest_behavior_for_classic_dashboard_remains_normal(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authorized_administrator_passes_the_outer_dashboard_boundary(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $this->actingAs($admin)->get('/dashboard/admin/users')->assertOk();
    }
}
