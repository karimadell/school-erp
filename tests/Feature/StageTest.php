<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Portal-eligible but unprivileged ('reception', active): clears
     * EnsureAdministrativePortalAccess but lacks 'manage stages', so the
     * negative tests exercise the real 403 gate, not a portal redirect.
     */
    protected function portalUser(): User
    {
        (new RolesAndPermissionsSeeder)->run();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('reception');

        return $user;
    }

    protected function authorizedUser(): User
    {
        $user = $this->portalUser();

        // Matches the permission seeded in RolesAndPermissionsSeeder.php.
        Permission::findOrCreate('manage stages', 'web');
        $user->givePermissionTo('manage stages');

        return $user;
    }

    protected function makeClass(Stage $stage, string $gradeName, string $code): SchoolClass
    {
        $grade = Grade::create([
            'name' => $gradeName,
            'stage_id' => $stage->id,
        ]);

        return SchoolClass::forceCreate([
            'grade_id' => $grade->id,
            'code' => $code,
            'name_ar' => $code,
            'name_ru' => $code,
        ]);
    }

    protected function makeAcademicYear(string $name, bool $isActive): AcademicYear
    {
        return AcademicYear::create([
            'name' => $name,
            'start_date' => '2026-09-01',
            'end_date' => '2027-05-31',
            'is_active' => $isActive,
        ]);
    }

    protected function makeEnrollment(
        Student $student,
        AcademicYear $academicYear,
        Stage $stage,
        SchoolClass $class,
        string $status = 'active',
        bool $isActive = true,
    ): Enrollment {
        return Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $academicYear->id,
            'stage_id' => $stage->id,
            'grade_id' => $class->grade_id,
            'class_id' => $class->id,
            'enrollment_date' => now()->toDateString(),
            'status' => $status,
            'is_active' => $isActive,
        ]);
    }

    public function test_any_authenticated_user_can_view_the_index(): void
    {
        $user = $this->portalUser();

        $response = $this->actingAs($user)->get(route('dashboard.stages.index'));

        $response->assertOk();
        $response->assertSee('Ступени обучения');
    }

    public function test_index_displays_scoped_structure_and_current_student_counts(): void
    {
        $user = $this->portalUser();
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);
        $otherStage = Stage::create(['name' => 'Secondary', 'order' => 2, 'is_active' => true]);

        $firstClass = $this->makeClass($stage, 'Grade 1', '1A');
        $this->makeClass($stage, 'Grade 2', '2A');
        $this->makeClass($otherStage, 'Grade 3', '3A');

        $historicalYear = $this->makeAcademicYear('2025 / 2026', true);
        $historicalStudent = Student::forceCreate(['name' => 'Historical Student']);
        $this->makeEnrollment($historicalStudent, $historicalYear, $stage, $firstClass);

        $activeYear = $this->makeAcademicYear('2026 / 2027', true);
        $currentStudent = Student::forceCreate(['name' => 'Current Student']);
        $inactiveStudent = Student::forceCreate(['name' => 'Inactive Student']);
        $withdrawnStudent = Student::forceCreate(['name' => 'Withdrawn Student']);
        $otherStageStudent = Student::forceCreate(['name' => 'Other Stage Student']);

        $this->makeEnrollment($currentStudent, $activeYear, $stage, $firstClass);
        $this->makeEnrollment($inactiveStudent, $activeYear, $stage, $firstClass, 'active', false);
        $this->makeEnrollment($withdrawnStudent, $activeYear, $stage, $firstClass, 'withdrawn');

        $otherClass = SchoolClass::query()
            ->whereHas('grade', fn ($query) => $query->where('stage_id', $otherStage->id))
            ->firstOrFail();
        $this->makeEnrollment($otherStageStudent, $activeYear, $otherStage, $otherClass);

        $response = $this->actingAs($user)->get(route('dashboard.stages.index'));

        $response->assertOk();
        $response->assertViewHas('stages', function ($stages) use ($stage, $otherStage) {
            $primary = $stages->firstWhere('id', $stage->id);
            $secondary = $stages->firstWhere('id', $otherStage->id);

            return $primary->grades_count === 2
                && $primary->school_classes_count === 2
                && (int) $primary->current_students_count === 1
                && $secondary->grades_count === 1
                && $secondary->school_classes_count === 1
                && (int) $secondary->current_students_count === 1;
        });
    }

    public function test_index_reports_zero_current_students_without_an_active_academic_year(): void
    {
        $user = $this->portalUser();
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);
        $class = $this->makeClass($stage, 'Grade 1', '1A');
        $historicalYear = $this->makeAcademicYear('2025 / 2026', true);
        $student = Student::forceCreate(['name' => 'Historical Student']);
        $this->makeEnrollment($student, $historicalYear, $stage, $class);
        $historicalYear->update(['is_active' => false]);

        $response = $this->actingAs($user)->get(route('dashboard.stages.index'));

        $response->assertOk();
        $response->assertViewHas('stages', fn ($stages) =>
            (int) $stages->firstWhere('id', $stage->id)->current_students_count === 0
        );
    }

    public function test_authenticated_user_can_view_stage_details_and_index_links_to_them(): void
    {
        $user = $this->portalUser();
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);

        $this->actingAs($user)
            ->get(route('dashboard.stages.index'))
            ->assertOk()
            ->assertSee(route('dashboard.stages.show', $stage), false);

        $this->actingAs($user)
            ->get(route('dashboard.stages.show', $stage))
            ->assertOk()
            ->assertSee('Primary');
    }

    public function test_unauthenticated_user_is_redirected_from_stage_details(): void
    {
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);

        $this->get(route('dashboard.stages.show', $stage))
            ->assertRedirect(route('login'));
    }

    public function test_stage_details_show_only_selected_hierarchy_and_scoped_current_counts(): void
    {
        $user = $this->portalUser();
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);
        $otherStage = Stage::create(['name' => 'Secondary', 'order' => 2, 'is_active' => true]);
        $firstClass = $this->makeClass($stage, 'Grade 1', '1A');
        $secondClass = $this->makeClass($stage, 'Grade 2', '2A');
        $otherClass = $this->makeClass($otherStage, 'Other Grade', 'OTHER-GROUP');

        $historicalYear = $this->makeAcademicYear('2025 / 2026', true);
        $this->makeEnrollment(
            Student::forceCreate(['name' => 'Historical Student']),
            $historicalYear,
            $stage,
            $firstClass
        );

        $activeYear = $this->makeAcademicYear('2026 / 2027', true);
        $this->makeEnrollment(Student::forceCreate(['name' => 'Current One']), $activeYear, $stage, $firstClass);
        $this->makeEnrollment(Student::forceCreate(['name' => 'Current Two']), $activeYear, $stage, $firstClass);
        $this->makeEnrollment(Student::forceCreate(['name' => 'Current Three']), $activeYear, $stage, $secondClass);
        $this->makeEnrollment(Student::forceCreate(['name' => 'Inactive']), $activeYear, $stage, $firstClass, 'active', false);
        $this->makeEnrollment(Student::forceCreate(['name' => 'Withdrawn']), $activeYear, $stage, $firstClass, 'withdrawn');
        $this->makeEnrollment(Student::forceCreate(['name' => 'Other Stage']), $activeYear, $otherStage, $otherClass);

        $response = $this->actingAs($user)->get(route('dashboard.stages.show', $stage));

        $response->assertOk();
        $response->assertSeeInOrder(['Grade 1', '1A', 'Grade 2', '2A']);
        $response->assertDontSee('Other Grade');
        $response->assertDontSee('OTHER-GROUP');
        $response->assertViewHas('stage', function (Stage $viewStage) use ($firstClass, $secondClass) {
            $firstGrade = $viewStage->grades->firstWhere('id', $firstClass->grade_id);
            $secondGrade = $viewStage->grades->firstWhere('id', $secondClass->grade_id);

            return (int) $viewStage->current_students_count === 3
                && (int) $firstGrade->current_students_count === 2
                && (int) $secondGrade->current_students_count === 1
                && (int) $firstGrade->classes->firstWhere('id', $firstClass->id)->current_students_count === 2
                && (int) $secondGrade->classes->firstWhere('id', $secondClass->id)->current_students_count === 1;
        });
    }

    public function test_stage_details_show_zero_counts_and_message_without_active_academic_year(): void
    {
        $user = $this->portalUser();
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);
        $class = $this->makeClass($stage, 'Grade 1', '1A');
        $year = $this->makeAcademicYear('2025 / 2026', true);
        $this->makeEnrollment(Student::forceCreate(['name' => 'Historical']), $year, $stage, $class);
        $year->update(['is_active' => false]);

        $response = $this->actingAs($user)->get(route('dashboard.stages.show', $stage));

        $response->assertOk();
        $response->assertSee('Нет активного учебного года');
        $response->assertViewHas('stage', function (Stage $viewStage) {
            $grade = $viewStage->grades->first();

            return (int) $viewStage->current_students_count === 0
                && (int) $grade->current_students_count === 0
                && (int) $grade->classes->first()->current_students_count === 0;
        });
    }

    public function test_empty_stage_details_render_successfully(): void
    {
        $user = $this->portalUser();
        $stage = Stage::create(['name' => 'Empty Stage', 'order' => 1, 'is_active' => true]);

        $this->actingAs($user)
            ->get(route('dashboard.stages.show', $stage))
            ->assertOk()
            ->assertSee('В этой ступени пока нет классов');
    }

    public function test_stage_management_actions_follow_grade_and_class_permissions(): void
    {
        foreach (['manage grades', 'manage classes'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);
        $this->makeClass($stage, 'Grade 1', '1A');

        $gradeManager = $this->portalUser();
        $gradeManager->givePermissionTo('manage grades');
        $this->actingAs($gradeManager)
            ->get(route('dashboard.stages.show', $stage))
            ->assertOk()
            ->assertSee('Добавить класс')
            ->assertSee('Редактировать класс')
            ->assertDontSee('Добавить учебную группу');

        $classManager = $this->portalUser();
        $classManager->givePermissionTo('manage classes');
        $this->actingAs($classManager)
            ->get(route('dashboard.stages.show', $stage))
            ->assertOk()
            ->assertSee('Добавить учебную группу')
            ->assertSee('Редактировать учебную группу')
            ->assertDontSee('Добавить класс');

        $readOnlyUser = $this->portalUser();
        $this->actingAs($readOnlyUser)
            ->get(route('dashboard.stages.show', $stage))
            ->assertOk()
            ->assertDontSee('Добавить класс')
            ->assertDontSee('Добавить учебную группу')
            ->assertDontSee('Редактировать учебную группу');
    }

    public function test_stage_detail_query_count_does_not_grow_per_grade_or_school_class(): void
    {
        $user = $this->portalUser();
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);
        $this->makeAcademicYear('2026 / 2027', true);
        $this->makeClass($stage, 'Grade 1', '1A');

        DB::enableQueryLog();
        $this->actingAs($user)->get(route('dashboard.stages.show', $stage))->assertOk();
        DB::flushQueryLog();
        $this->actingAs($user)->get(route('dashboard.stages.show', $stage))->assertOk();
        $initialQueryCount = count(DB::getQueryLog());

        for ($number = 2; $number <= 5; $number++) {
            $this->makeClass($stage, "Grade {$number}", "{$number}A");
        }

        DB::flushQueryLog();
        $this->actingAs($user)->get(route('dashboard.stages.show', $stage))->assertOk();
        $expandedQueryCount = count(DB::getQueryLog());

        $this->assertSame($initialQueryCount, $expandedQueryCount);
        $this->assertLessThanOrEqual(7, $expandedQueryCount);
    }

    public function test_unauthorized_user_cannot_open_create_page(): void
    {
        $user = $this->portalUser();

        $response = $this->actingAs($user)->get(route('dashboard.stages.create'));

        $response->assertForbidden();
    }

    public function test_unauthorized_user_cannot_store_a_stage(): void
    {
        $user = $this->portalUser();

        $response = $this->actingAs($user)->post(route('dashboard.stages.store'), [
            'name' => 'Primary',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('stages', 0);
    }

    public function test_authorized_user_can_store_a_stage(): void
    {
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->post(route('dashboard.stages.store'), [
            'name' => 'Primary',
            'order' => 7,
        ]);

        $response->assertRedirect(route('dashboard.stages.index'));
        $this->assertDatabaseHas('stages', [
            'name' => 'Primary',
            'order' => 7,
        ]);
    }

    public function test_stage_persists_is_active_false(): void
    {
        $stage = Stage::create([
            'name' => 'Inactive Stage',
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('stages', [
            'id' => $stage->id,
            'is_active' => false,
        ]);
    }

    public function test_authorized_user_can_update_a_stage(): void
    {
        $user = $this->authorizedUser();
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);

        $response = $this->actingAs($user)->put(route('dashboard.stages.update', $stage), [
            'name' => 'Primary Renamed',
        ]);

        $response->assertRedirect(route('dashboard.stages.index'));
        $this->assertSame('Primary Renamed', $stage->fresh()->name);
    }

    public function test_unauthorized_user_cannot_delete_a_stage(): void
    {
        $user = $this->authorizedUser();
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);

        // Revoke permission to confirm destroy is actually gated.
        $user->revokePermissionTo('manage stages');

        $response = $this->actingAs($user)->delete(route('dashboard.stages.destroy', $stage));

        $response->assertForbidden();
        $this->assertDatabaseHas('stages', ['id' => $stage->id]);
    }
}
