<?php

namespace Tests\Feature\Teacher;

use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherLoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
    }

    protected function user(string $role, bool $userActive = true, ?bool $teacherActive = null): User
    {
        $user = User::factory()->create(['is_active' => $userActive]);
        $user->assignRole($role);

        if ($teacherActive !== null) {
            Teacher::create([
                'user_id' => $user->id,
                'first_name' => 'Anna',
                'last_name' => 'Ivanova',
                'is_active' => $teacherActive,
            ]);
        }

        return $user;
    }

    protected function login(User $user)
    {
        return $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    }

    public function test_classic_login_redirects_valid_users_to_their_own_portal(): void
    {
        $teacher = $this->user('teacher', teacherActive: true);
        $this->login($teacher)->assertRedirect('/teacher');

        $this->post('/logout');

        $admin = $this->user('admin');
        $this->login($admin)->assertRedirect('/dashboard');
    }

    public function test_disabled_user_is_logged_back_out(): void
    {
        $user = $this->user('admin', userActive: false);

        $this->login($user)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_teacher_with_missing_or_inactive_link_never_falls_back_to_dashboard(): void
    {
        foreach ([$this->user('teacher'), $this->user('teacher', teacherActive: false)] as $teacher) {
            $response = $this->login($teacher);

            $response->assertSessionHasErrors('email');
            $response->assertRedirect('/');
            $this->assertGuest();
        }
    }

    public function test_authenticated_teacher_cannot_enter_the_classic_shell_via_login_or_root(): void
    {
        $teacher = $this->user('teacher', teacherActive: true);

        $this->actingAs($teacher)->get('/login')->assertRedirect('/dashboard');
        $this->actingAs($teacher)->get('/dashboard')->assertRedirect('/teacher');
        $this->actingAs($teacher)->get('/')->assertRedirect('/dashboard');
        $this->actingAs($teacher)->followingRedirects()->get('/')->assertOk();
    }
}
