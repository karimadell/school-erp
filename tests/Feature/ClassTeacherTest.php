<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassResource\Pages\EditClass;
use App\Filament\Resources\ClassResource\RelationManagers\ClassTeachersRelationManager;
use App\Models\AcademicYear;
use App\Models\ClassTeacher;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Batch 11 / C4: Class Teacher assignment (классный руководитель) —
 * SchoolClass x Teacher x Academic Year, implemented as a RelationManager
 * on ClassResource. Reuses the existing 'manage classes' permission —
 * no new permission introduced. Does not implement ResolvesAcademicYear
 * (deferred). SchoolClass itself gets no policy change — authorization
 * comes entirely from ClassTeacherPolicy.
 */
class ClassTeacherTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithRole(string $role): User
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function makeYear(bool $active = true): AcademicYear
    {
        return AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => $active,
        ]);
    }

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);

        return SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'a']);
    }

    protected function makeTeacher(): Teacher
    {
        return Teacher::create(['first_name' => 'Anna', 'last_name' => 'Ivanova', 'is_active' => true]);
    }

    /*
    |--------------------------------------------------------------------------
    | Model / schema
    |--------------------------------------------------------------------------
    */

    public function test_a_class_teacher_row_can_be_created_with_valid_data(): void
    {
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $year = $this->makeYear();

        $classTeacher = ClassTeacher::create([
            'school_class_id' => $class->id,
            'teacher_id' => $teacher->id,
            'academic_year_id' => $year->id,
        ]);

        $this->assertDatabaseHas('class_teachers', [
            'id' => $classTeacher->id,
            'school_class_id' => $class->id,
            'teacher_id' => $teacher->id,
            'academic_year_id' => $year->id,
        ]);
    }

    public function test_relations_resolve_correctly(): void
    {
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $year = $this->makeYear();

        $classTeacher = ClassTeacher::create([
            'school_class_id' => $class->id, 'teacher_id' => $teacher->id, 'academic_year_id' => $year->id,
        ]);

        $this->assertTrue($classTeacher->schoolClass->is($class));
        $this->assertTrue($classTeacher->teacher->is($teacher));
        $this->assertTrue($classTeacher->academicYear->is($year));
        $this->assertTrue($class->classTeachers->contains($classTeacher));
        $this->assertTrue($teacher->classTeacherAssignments->contains($classTeacher));
        $this->assertTrue($year->classTeachers->contains($classTeacher));
    }

    public function test_the_database_rejects_a_second_homeroom_teacher_for_the_same_class_and_year(): void
    {
        $class = $this->makeClass();
        $teacherOne = $this->makeTeacher();
        $teacherTwo = $this->makeTeacher();
        $year = $this->makeYear();

        ClassTeacher::create([
            'school_class_id' => $class->id, 'teacher_id' => $teacherOne->id, 'academic_year_id' => $year->id,
        ]);

        $this->expectException(QueryException::class);

        ClassTeacher::create([
            'school_class_id' => $class->id, 'teacher_id' => $teacherTwo->id, 'academic_year_id' => $year->id,
        ]);
    }

    public function test_the_same_teacher_can_be_homeroom_for_multiple_classes(): void
    {
        $classOne = $this->makeClass();
        $classTwo = $this->makeClass();
        $teacher = $this->makeTeacher();
        $year = $this->makeYear();

        ClassTeacher::create([
            'school_class_id' => $classOne->id, 'teacher_id' => $teacher->id, 'academic_year_id' => $year->id,
        ]);
        ClassTeacher::create([
            'school_class_id' => $classTwo->id, 'teacher_id' => $teacher->id, 'academic_year_id' => $year->id,
        ]);

        $this->assertDatabaseCount('class_teachers', 2);
    }

    public function test_the_same_class_can_have_a_different_homeroom_teacher_in_a_different_year(): void
    {
        $class = $this->makeClass();
        $teacherOne = $this->makeTeacher();
        $teacherTwo = $this->makeTeacher();
        $yearOne = $this->makeYear();
        $yearTwo = AcademicYear::create([
            'name' => '2027 / 2028', 'start_date' => '2027-09-01', 'end_date' => '2028-05-31', 'is_active' => false,
        ]);

        ClassTeacher::create([
            'school_class_id' => $class->id, 'teacher_id' => $teacherOne->id, 'academic_year_id' => $yearOne->id,
        ]);
        $second = ClassTeacher::create([
            'school_class_id' => $class->id, 'teacher_id' => $teacherTwo->id, 'academic_year_id' => $yearTwo->id,
        ]);

        $this->assertDatabaseCount('class_teachers', 2);
        $this->assertSame($teacherTwo->id, $second->teacher_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization — reuses 'manage classes', no new permission
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_view_the_relation_manager(): void
    {
        $user = $this->userWithRole('admin');
        $class = $this->makeClass();

        Livewire::actingAs($user)
            ->test(ClassTeachersRelationManager::class, ['ownerRecord' => $class, 'pageClass' => EditClass::class])
            ->assertSuccessful();
    }

    public function test_a_role_without_manage_classes_cannot_create_a_class_teacher_via_the_relation_manager(): void
    {
        // Mounting the relation manager component directly (bypassing the
        // parent EditRecord page, which is what would normally hide this
        // tab entirely for an unauthorized user) doesn't itself 403 —
        // that's a UI-visibility nuance of how Filament's RelationManager
        // works, not a security boundary. The real security question is
        // whether the create action itself is still gated by
        // ClassTeacherPolicy — confirmed here: it is.
        $user = $this->userWithRole('accountant');
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $year = $this->makeYear();

        Livewire::actingAs($user)
            ->test(ClassTeachersRelationManager::class, ['ownerRecord' => $class, 'pageClass' => EditClass::class])
            ->assertTableActionHidden('create');

        $this->assertDatabaseCount('class_teachers', 0);
    }

    public function test_principal_can_view_the_relation_manager(): void
    {
        // 'manage classes' is already held by principal — confirms the
        // reused permission reaches the intended role without any new
        // permission having to be introduced.
        $user = $this->userWithRole('principal');
        $class = $this->makeClass();

        Livewire::actingAs($user)
            ->test(ClassTeachersRelationManager::class, ['ownerRecord' => $class, 'pageClass' => EditClass::class])
            ->assertSuccessful();
    }

    /*
    |--------------------------------------------------------------------------
    | Relation manager — create + duplicate validation
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_assign_a_class_teacher_via_the_relation_manager(): void
    {
        $user = $this->userWithRole('admin');
        $class = $this->makeClass();
        $teacher = $this->makeTeacher();
        $year = $this->makeYear();

        Livewire::actingAs($user)
            ->test(ClassTeachersRelationManager::class, ['ownerRecord' => $class, 'pageClass' => EditClass::class])
            ->callTableAction('create', data: [
                'teacher_id' => $teacher->id,
                'academic_year_id' => $year->id,
            ]);

        $this->assertDatabaseHas('class_teachers', [
            'school_class_id' => $class->id,
            'teacher_id' => $teacher->id,
            'academic_year_id' => $year->id,
        ]);
    }

    public function test_the_relation_manager_surfaces_a_validation_error_for_a_duplicate_assignment(): void
    {
        $user = $this->userWithRole('admin');
        $class = $this->makeClass();
        $teacherOne = $this->makeTeacher();
        $teacherTwo = $this->makeTeacher();
        $year = $this->makeYear();

        ClassTeacher::create([
            'school_class_id' => $class->id, 'teacher_id' => $teacherOne->id, 'academic_year_id' => $year->id,
        ]);

        Livewire::actingAs($user)
            ->test(ClassTeachersRelationManager::class, ['ownerRecord' => $class, 'pageClass' => EditClass::class])
            ->callTableAction('create', data: [
                'teacher_id' => $teacherTwo->id,
                'academic_year_id' => $year->id,
            ])
            ->assertHasTableActionErrors(['academic_year_id' => 'unique']);

        $this->assertDatabaseCount('class_teachers', 1);
    }
}
