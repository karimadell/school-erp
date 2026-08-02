<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
    }

    protected function userWithRole(string $role, bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active]);
        $user->assignRole($role);

        return $user;
    }

    protected function activeTeacherUser(bool $userActive = true, bool $teacherActive = true): User
    {
        $user = $this->userWithRole('teacher', $userActive);
        Teacher::create([
            'user_id' => $user->id,
            'first_name' => 'Anna',
            'last_name' => 'Ivanova',
            'is_active' => $teacherActive,
        ]);

        return $user;
    }

    public function test_active_linked_teacher_can_access_only_the_teacher_panel(): void
    {
        $teacher = $this->activeTeacherUser();

        $this->actingAs($teacher)->get('/teacher')->assertOk();
        $this->actingAs($teacher)->get('/admin')->assertForbidden();
    }

    public function test_admin_cannot_access_the_teacher_panel(): void
    {
        $this->actingAs($this->userWithRole('admin'))->get('/teacher')->assertForbidden();
    }

    public function test_teacher_requires_an_active_linked_teacher_record(): void
    {
        $unlinked = $this->userWithRole('teacher');
        $inactive = $this->activeTeacherUser(teacherActive: false);

        $this->actingAs($unlinked)->get('/teacher')->assertForbidden();
        $this->actingAs($inactive)->get('/teacher')->assertForbidden();
    }

    public function test_disabled_users_cannot_access_either_panel(): void
    {
        $teacher = $this->activeTeacherUser(userActive: false);
        $admin = $this->userWithRole('admin', active: false);

        $this->actingAs($teacher)->get('/teacher')->assertForbidden();
        $this->actingAs($admin)->get('/admin')->assertForbidden();
    }

    public function test_guests_are_sent_to_each_panels_own_login(): void
    {
        $this->get('/teacher')->assertRedirect('/teacher/login');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_user_without_a_role_is_denied_from_both_panels(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/teacher')->assertForbidden();
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }
}
