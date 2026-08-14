<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batch A (Modern UI/UX readiness): the guest (login/register) layout never
 * set a `dir` attribute, so Arabic-locale visitors got a broken LTR login
 * screen; the Breeze auth strings (Email/Password/etc.) were never
 * translated, so Russian-locale visitors — the primary audience — saw raw
 * English on the very first screen. Both are fixed via lang/ru.json plus
 * a `dir` attribute on layouts/guest.blade.php, matching the pattern
 * layouts/dashboard.blade.php already used.
 */
class GuestLayoutLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_localized_to_russian_by_default(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Пароль');
        $response->assertSee('Войти');
        $response->assertSee('Запомнить меня');
    }

    public function test_login_page_is_left_to_right_under_the_default_russian_locale(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('dir="ltr"', false);
    }

    public function test_login_page_switches_to_right_to_left_under_arabic_locale(): void
    {
        $response = $this->withSession(['locale' => 'ar'])->get('/login');

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
    }

    public function test_registration_is_unavailable_and_confirm_password_is_localized_to_russian(): void
    {
        $this->get('/register')->assertNotFound();

        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)
            ->get(route('password.confirm'))
            ->assertOk()
            ->assertSee('Подтвердить');
    }

    public function test_dashboard_layout_has_a_mobile_viewport_meta_tag(): void
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this->actingAs($user)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee('name="viewport"', false);
    }
}
