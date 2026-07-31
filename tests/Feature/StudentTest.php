<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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

    /**
     * Batch 6: 'manage students' was split into per-action permissions
     * (view/create/update/delete students) so Reception can be granted
     * create+view+update without delete. This fixture grants all four,
     * matching the old 'manage students' scope for tests that don't care
     * about the distinction.
     */
    protected function authorizedUser(): User
    {
        $user = User::factory()->create();

        foreach (['view students', 'create students', 'update students', 'delete students'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo(['view students', 'create students', 'update students', 'delete students']);

        return $user;
    }

    public function test_a_user_without_view_permission_cannot_view_the_index(): void
    {
        // Batch 6: deny by default — viewing the index now requires
        // 'view students', replacing the old "any authenticated user" rule.
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.students.index'));

        $response->assertForbidden();
    }

    public function test_a_user_with_view_permission_can_view_the_index(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('view students', 'web');
        $user->givePermissionTo('view students');

        $response = $this->actingAs($user)->get(route('dashboard.students.index'));

        $response->assertOk();
    }

    public function test_show_page_renders_with_a_current_enrollment(): void
    {
        $user = $this->authorizedUser();
        $class = $this->makeClass();
        $student = Student::forceCreate(['name' => 'Enrolled Student']);
        $year = AcademicYear::create([
            'name' => '2026 / 2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-05-31',
            'is_active' => true,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'stage_id' => $class->grade->stage_id,
            'grade_id' => $class->grade_id,
            'class_id' => $class->id,
            'enrollment_date' => '2026-09-01',
            'status' => 'active',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.students.show', $student));

        $response->assertOk();
        $response->assertViewHas('currentEnrollment', function ($currentEnrollment) use ($year) {
            return $currentEnrollment?->academic_year_id === $year->id;
        });
    }

    public function test_show_page_renders_without_a_current_enrollment(): void
    {
        $user = $this->authorizedUser();
        $student = Student::forceCreate(['name' => 'Unenrolled Student']);

        $response = $this->actingAs($user)->get(route('dashboard.students.show', $student));

        $response->assertOk();
        $response->assertViewHas('currentEnrollment', null);
    }

    public function test_financial_route_is_not_registered(): void
    {
        $this->assertFalse(Route::has('dashboard.students.financial'));
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

        // Revoke only delete — create/update/view remain, proving the
        // permissions are independently enforced, not bundled together.
        $user->revokePermissionTo('delete students');

        $response = $this->actingAs($user)->delete(route('dashboard.students.destroy', $student));

        $response->assertForbidden();
        $this->assertDatabaseHas('students', ['id' => $student->id]);
    }

    public function test_a_user_with_create_and_view_but_not_delete_cannot_delete(): void
    {
        // Proves Reception's exact grant (create/view/update, no delete)
        // is correctly enforced end-to-end.
        $user = User::factory()->create();
        foreach (['view students', 'create students', 'update students'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo(['view students', 'create students', 'update students']);

        $class = $this->makeClass();
        $student = Student::create([
            'class_id' => $class->id,
            'last_name_ru' => 'Sidorov',
            'first_name_ru' => 'Ivan',
        ]);

        $this->actingAs($user)->get(route('dashboard.students.index'))->assertOk();
        $this->actingAs($user)->delete(route('dashboard.students.destroy', $student))->assertForbidden();
        $this->assertDatabaseHas('students', ['id' => $student->id]);
    }
}
