<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
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

    public function test_authorized_user_can_store_a_student(): void
    {
        // Regression test: students.name was NOT NULL in the database but
        // StudentController never populated it (only the RU name fields),
        // so every student insert threw a NOT NULL constraint violation,
        // unrelated to permissions.
        $user = $this->authorizedUser();
        $class = $this->makeClass();

        $response = $this->actingAs($user)->post(route('dashboard.students.store'), [
            'class_id' => $class->id,
            'last_name_ru' => 'Ivanov',
            'first_name_ru' => 'Ivan',
        ]);

        $response->assertRedirect(route('dashboard.students.index'));
        $this->assertDatabaseHas('students', ['first_name_ru' => 'Ivan', 'last_name_ru' => 'Ivanov']);
    }

    public function test_unauthorized_user_cannot_delete_a_student(): void
    {
        $user = $this->authorizedUser();
        $class = $this->makeClass();

        $student = Student::create([
            'class_id' => $class->id,
            'last_name_ru' => 'Petrov',
            'first_name_ru' => 'Petr',
        ]);

        // Revoke permission to confirm destroy is actually gated.
        $user->revokePermissionTo('manage students');

        $response = $this->actingAs($user)->delete(route('dashboard.students.destroy', $student));

        $response->assertForbidden();
        $this->assertDatabaseHas('students', ['id' => $student->id]);
    }
}
