<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SubjectTest extends TestCase
{
    use RefreshDatabase;

    protected function authorizedUser(): User
    {
        $user = User::factory()->create();

        Permission::findOrCreate('manage subjects', 'web');
        $user->givePermissionTo('manage subjects');

        return $user;
    }

    public function test_any_authenticated_user_can_view_the_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.subjects.index'));

        $response->assertOk();
    }

    public function test_unauthorized_user_cannot_open_create_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.subjects.create'));

        $response->assertForbidden();
    }

    public function test_unauthorized_user_cannot_store_a_subject(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('dashboard.subjects.store'), [
            'name_ru' => 'Mathematics',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('subjects', 0);
    }

    // NOTE: authorized-store / delete-permission tests that require a
    // successful Subject save are added separately once the pre-existing
    // Subject::name_ar NOT NULL / fillable bug (discovered while writing
    // this test, unrelated to permission middleware) is fixed.
}
