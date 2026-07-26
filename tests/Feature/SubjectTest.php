<?php

namespace Tests\Feature;

use App\Models\Subject;
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

    public function test_authorized_user_can_store_a_subject(): void
    {
        // Regression test: subjects.name_ar is NOT NULL in the database
        // but was missing from Subject::$fillable, and the controller
        // never populated it — SubjectController::store() always threw a
        // NOT NULL constraint violation, unrelated to permissions.
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->post(route('dashboard.subjects.store'), [
            'name_ru' => 'Mathematics',
        ]);

        $response->assertRedirect(route('dashboard.subjects.index'));
        $this->assertDatabaseHas('subjects', ['name_ru' => 'Mathematics', 'name_ar' => 'Mathematics']);
    }

    public function test_unauthorized_user_cannot_delete_a_subject(): void
    {
        $user = $this->authorizedUser();

        $subject = Subject::create([
            'code' => 'MATH',
            'name_ar' => 'الرياضيات',
            'name_ru' => 'Mathematics',
            'is_active' => true,
        ]);

        // Revoke permission to confirm destroy is actually gated.
        $user->revokePermissionTo('manage subjects');

        $response = $this->actingAs($user)->delete(route('dashboard.subjects.destroy', $subject));

        $response->assertForbidden();
        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
    }
}
