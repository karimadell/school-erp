<?php

namespace Tests\Feature;

use App\Filament\Resources\TeacherAssignments\Pages\CreateTeacherAssignment;
use App\Filament\Resources\TeacherAssignments\Pages\ListTeacherAssignments;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Support\AcademicYearLock;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Batch 8: the minimal admin-side management screen for TeacherAssignment
 * (approved policy decision — build it now, gated on the existing
 * 'manage teachers' permission, same as TeacherController). Also proves
 * the composite (teacher, class, subject, academic_year) uniqueness both
 * at the database and the Filament form level.
 */
class TeacherAssignmentResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithRole(string $role): User
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function makeClass(): SchoolClass
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);

        return SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'A-' . uniqid(),
            'name_ar' => 'فصل',
            'name_ru' => 'Класс',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization — gated on 'manage teachers', matching TeacherController
    |--------------------------------------------------------------------------
    */

    public function test_a_role_without_manage_teachers_cannot_open_the_resource(): void
    {
        $user = $this->userWithRole('accountant');

        Livewire::actingAs($user)->test(ListTeacherAssignments::class)->assertForbidden();
    }

    public function test_school_admin_can_open_the_resource(): void
    {
        $user = $this->userWithRole('school-admin');

        Livewire::actingAs($user)->test(ListTeacherAssignments::class)->assertSuccessful();
    }

    public function test_a_role_without_manage_teachers_cannot_create_an_assignment(): void
    {
        $user = $this->userWithRole('reception');

        Livewire::actingAs($user)->test(CreateTeacherAssignment::class)->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Composite uniqueness — (teacher, class, subject, academic_year)
    |--------------------------------------------------------------------------
    */

    public function test_the_database_rejects_a_duplicate_assignment(): void
    {
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $class = $this->makeClass();
        $subject = Subject::create(['code' => 'SUBJ', 'name_ar' => 'a', 'name_ru' => 'a']);
        $teacher = Teacher::create(['first_name' => 'A', 'last_name' => 'B', 'is_active' => true]);

        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);
    }

    public function test_the_same_teacher_class_subject_is_allowed_in_a_different_academic_year(): void
    {
        $yearOne = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);
        $yearTwo = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $class = $this->makeClass();
        $subject = Subject::create(['code' => 'SUBJ', 'name_ar' => 'a', 'name_ru' => 'a']);
        $teacher = Teacher::create(['first_name' => 'A', 'last_name' => 'B', 'is_active' => true]);

        AcademicYearLock::withoutLock(fn () => TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $yearOne->id,
        ]));

        $second = TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $yearTwo->id,
        ]);

        $this->assertDatabaseCount('teacher_assignments', 2);
        $this->assertSame($yearTwo->id, $second->academic_year_id);
    }

    public function test_the_filament_form_surfaces_a_validation_error_for_a_duplicate_assignment(): void
    {
        $user = $this->userWithRole('admin');
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $class = $this->makeClass();
        $subject = Subject::create(['code' => 'SUBJ', 'name_ar' => 'a', 'name_ru' => 'a']);
        $teacher = Teacher::create(['first_name' => 'A', 'last_name' => 'B', 'is_active' => true]);

        TeacherAssignment::create([
            'teacher_id' => $teacher->id, 'class_id' => $class->id,
            'subject_id' => $subject->id, 'academic_year_id' => $year->id,
        ]);

        Livewire::actingAs($user)
            ->test(CreateTeacherAssignment::class)
            ->fillForm([
                'teacher_id' => $teacher->id,
                'class_id' => $class->id,
                'subject_id' => $subject->id,
                'academic_year_id' => $year->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['academic_year_id' => 'unique']);

        $this->assertDatabaseCount('teacher_assignments', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Audit trail — TeacherAssignment is a sensitive access-control record
    |--------------------------------------------------------------------------
    */

    public function test_creating_an_assignment_is_audited(): void
    {
        $user = $this->userWithRole('admin');
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
        $class = $this->makeClass();
        $subject = Subject::create(['code' => 'SUBJ', 'name_ar' => 'a', 'name_ru' => 'a']);
        $teacher = Teacher::create(['first_name' => 'A', 'last_name' => 'B', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(CreateTeacherAssignment::class)
            ->fillForm([
                'teacher_id' => $teacher->id,
                'class_id' => $class->id,
                'subject_id' => $subject->id,
                'academic_year_id' => $year->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'model' => 'TeacherAssignment',
        ]);
    }
}
