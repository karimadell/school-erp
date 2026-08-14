<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_is_unavailable(): void
    {
        $response = $this->get('/register');

        $response->assertNotFound();
    }

    public function test_registration_post_is_unavailable_and_creates_nothing(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $response->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
