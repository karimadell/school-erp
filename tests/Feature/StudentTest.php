<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StudentTest extends TestCase
{
    use RefreshDatabase;

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);

        return SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'A',
            'name_ar' => 'فصل A',
            'name_ru' => 'Класс A',
            'capacity' => 25,
            'is_active' => true,
        ]);
    }

    protected function authorizedUser(): User
    {
        $user = User::factory()->create();

        // Matches the permission actually seeded in
        // RolesAndPermissionsSeeder.php ('manage students').
        Permission::findOrCreate('manage students', 'web');
        $user->givePermissionTo('manage students');

        return $user;
    }

    public function test_any_authenticated_user_can_view_the_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.students.index'));

        $response->assertOk();
    }

    public function test_unauthorized_user_cannot_open_create_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.students.create'));

        $response->assertForbidden();
    }

    public function test_unauthorized_user_cannot_store_a_student(): void
    {
        $user = User::factory()->create();
        $class = $this->makeClass();

        $response = $this->actingAs($user)->post(route('dashboard.students.store'), [
            'class_id' => $class->id,
            'last_name_ru' => 'Ivanov',
            'first_name_ru' => 'Ivan',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('students', 0);
    }

    // NOTE: an authorized-store test and a delete-permission test that
    // require a successful Student save are added separately once the
    // pre-existing students.name NOT NULL bug (discovered while writing
    // this test, unrelated to permission middleware) is fixed.
}
