<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root route intentionally redirects to the dashboard.
     */
    public function test_the_root_route_redirects_to_the_dashboard(): void
    {
        $response = $this->get('/');

        $response->assertStatus(302);
        $response->assertRedirect('/dashboard');
    }
}
