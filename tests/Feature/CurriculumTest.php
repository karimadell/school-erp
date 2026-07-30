<?php

namespace Tests\Feature;

use App\Filament\Resources\Curricula\Pages\CreateCurriculum;
use App\Filament\Resources\Curricula\Pages\ListCurricula;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\Grade;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Item 8 / C1 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, Section 3):
 * Curriculum (Academic Year x Grade x Subject). Phase 1 — core entity
 * only: no Class-level override (deferred, undesigned per
 * docs/OPEN_POLICY_DECISIONS.md), no ResolvesAcademicYear/lock
 * integration (deferred), no copy-forward feature yet (Phase 2).
 */
class CurriculumTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithRole(string $role): User
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function makeYear(): AcademicYear
    {
        return AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);
    }

    protected function makeGrade(): Grade
    {
        $stage = Stage::create(['name' => 'Primary']);

        return Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
    }

    protected function makeSubject(): Subject
    {
        return Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => 'Математика']);
    }

    /*
    |--------------------------------------------------------------------------
    | Model / schema
    |--------------------------------------------------------------------------
    */

    public function test_a_curriculum_row_can_be_created_with_valid_data(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $subject = $this->makeSubject();

        $curriculum = Curriculum::create([
            'academic_year_id' => $year->id,
            'grade_id' => $grade->id,
            'subject_id' => $subject->id,
            'weekly_hours' => 4,
            'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $this->assertDatabaseHas('curricula', [
            'id' => $curriculum->id,
            'academic_year_id' => $year->id,
            'grade_id' => $grade->id,
            'subject_id' => $subject->id,
            'weekly_hours' => 4,
            'type' => 'mandatory',
        ]);
    }

    public function test_relations_resolve_correctly(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $subject = $this->makeSubject();

        $curriculum = Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 4, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $this->assertTrue($curriculum->academicYear->is($year));
        $this->assertTrue($curriculum->grade->is($grade));
        $this->assertTrue($curriculum->subject->is($subject));
        $this->assertTrue($year->curricula->contains($curriculum));
        $this->assertTrue($grade->curricula->contains($curriculum));
        $this->assertTrue($subject->curricula->contains($curriculum));
    }

    public function test_the_database_rejects_a_duplicate_year_grade_subject_combination(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $subject = $this->makeSubject();

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 4, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $this->expectException(QueryException::class);

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 2, 'type' => Curriculum::TYPE_ELECTIVE,
        ]);
    }

    public function test_the_same_grade_and_subject_is_allowed_in_a_different_academic_year(): void
    {
        $yearOne = $this->makeYear();
        $yearTwo = AcademicYear::create([
            'name' => '2027 / 2028', 'start_date' => '2027-09-01', 'end_date' => '2028-05-31', 'is_active' => false,
        ]);
        $grade = $this->makeGrade();
        $subject = $this->makeSubject();

        Curriculum::create([
            'academic_year_id' => $yearOne->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 4, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        $second = Curriculum::create([
            'academic_year_id' => $yearTwo->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 5, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $this->assertDatabaseCount('curricula', 2);
        $this->assertSame($yearTwo->id, $second->academic_year_id);
    }

    public function test_a_different_subject_is_allowed_for_the_same_year_and_grade(): void
    {
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $subjectOne = $this->makeSubject();
        $subjectTwo = $this->makeSubject();

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subjectOne->id,
            'weekly_hours' => 4, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subjectTwo->id,
            'weekly_hours' => 1, 'type' => Curriculum::TYPE_OPTIONAL_ENRICHMENT,
        ]);

        $this->assertDatabaseCount('curricula', 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization — 'manage curriculum', admin-only for this phase
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_open_the_resource(): void
    {
        $user = $this->userWithRole('admin');

        Livewire::actingAs($user)->test(ListCurricula::class)->assertSuccessful();
    }

    public function test_school_admin_cannot_open_the_resource(): void
    {
        // Approved decision: admin-only for this initial implementation —
        // not extended to school-admin, unlike most other academic
        // management permissions school-admin otherwise holds.
        $user = $this->userWithRole('school-admin');

        Livewire::actingAs($user)->test(ListCurricula::class)->assertForbidden();
    }

    public function test_principal_cannot_open_the_resource(): void
    {
        // Approved decision: not principal either, despite principal
        // already holding every other academic-management permission
        // (manage subjects/stages/grades/classes/academic years).
        $user = $this->userWithRole('principal');

        Livewire::actingAs($user)->test(ListCurricula::class)->assertForbidden();
    }

    public function test_a_role_without_manage_curriculum_cannot_create_a_row(): void
    {
        $user = $this->userWithRole('accountant');

        Livewire::actingAs($user)->test(CreateCurriculum::class)->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Filament — form-level duplicate validation
    |--------------------------------------------------------------------------
    */

    public function test_the_filament_form_surfaces_a_validation_error_for_a_duplicate_row(): void
    {
        $user = $this->userWithRole('admin');
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $subject = $this->makeSubject();

        Curriculum::create([
            'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 4, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        Livewire::actingAs($user)
            ->test(CreateCurriculum::class)
            ->fillForm([
                'academic_year_id' => $year->id,
                'grade_id' => $grade->id,
                'subject_id' => $subject->id,
                'weekly_hours' => 2,
                'type' => Curriculum::TYPE_ELECTIVE,
            ])
            ->call('create')
            ->assertHasFormErrors(['academic_year_id' => 'unique']);

        $this->assertDatabaseCount('curricula', 1);
    }

    public function test_admin_can_create_a_curriculum_row_via_the_filament_form(): void
    {
        $user = $this->userWithRole('admin');
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $subject = $this->makeSubject();

        Livewire::actingAs($user)
            ->test(CreateCurriculum::class)
            ->fillForm([
                'academic_year_id' => $year->id,
                'grade_id' => $grade->id,
                'subject_id' => $subject->id,
                'weekly_hours' => 3,
                'type' => Curriculum::TYPE_MANDATORY,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('curricula', [
            'academic_year_id' => $year->id,
            'grade_id' => $grade->id,
            'subject_id' => $subject->id,
            'weekly_hours' => 3,
            'type' => 'mandatory',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 2: copy-forward between academic years. Approved decisions:
    | target is always the active year (never user-selectable), source is
    | unconstrained, whole-year only, skip-if-exists (never overwrite),
    | reuses 'manage curriculum' (no new permission), no audit logging.
    |--------------------------------------------------------------------------
    */

    public function test_copying_duplicates_the_source_years_curricula_into_the_active_year(): void
    {
        $user = $this->userWithRole('admin');
        $sourceYear = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);
        $activeYear = $this->makeYear();
        $grade = $this->makeGrade();
        $subject = $this->makeSubject();

        Curriculum::create([
            'academic_year_id' => $sourceYear->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 4, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        Livewire::actingAs($user)
            ->test(ListCurricula::class)
            ->callAction('copyFromPreviousYear', ['source_academic_year_id' => $sourceYear->id])
            ->assertNotified(__('curriculum.copy_success', ['created' => 1, 'skipped' => 0]));

        $this->assertDatabaseHas('curricula', [
            'academic_year_id' => $activeYear->id,
            'grade_id' => $grade->id,
            'subject_id' => $subject->id,
            'weekly_hours' => 4,
            'type' => 'mandatory',
        ]);
    }

    public function test_copying_is_idempotent_and_skips_rows_that_already_exist_in_the_active_year(): void
    {
        $user = $this->userWithRole('admin');
        $sourceYear = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);
        $activeYear = $this->makeYear();
        $grade = $this->makeGrade();
        $subject = $this->makeSubject();

        Curriculum::create([
            'academic_year_id' => $sourceYear->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 4, 'type' => Curriculum::TYPE_MANDATORY,
        ]);

        $component = Livewire::actingAs($user)->test(ListCurricula::class);
        $component->callAction('copyFromPreviousYear', ['source_academic_year_id' => $sourceYear->id]);

        // Running it again must not duplicate or error — everything is
        // already there, so nothing is created, one row is skipped.
        $component->callAction('copyFromPreviousYear', ['source_academic_year_id' => $sourceYear->id])
            ->assertNotified(__('curriculum.copy_success', ['created' => 0, 'skipped' => 1]));

        $this->assertDatabaseCount('curricula', 2); // 1 source + 1 target, never duplicated
    }

    public function test_copying_never_overwrites_an_existing_target_row_even_if_values_differ(): void
    {
        $user = $this->userWithRole('admin');
        $sourceYear = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);
        $activeYear = $this->makeYear();
        $grade = $this->makeGrade();
        $subject = $this->makeSubject();

        Curriculum::create([
            'academic_year_id' => $sourceYear->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 4, 'type' => Curriculum::TYPE_MANDATORY,
        ]);
        // Target already has its own row for this grade/subject, with
        // different values — this must survive the copy untouched.
        $existingTarget = Curriculum::create([
            'academic_year_id' => $activeYear->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id,
            'weekly_hours' => 2, 'type' => Curriculum::TYPE_ELECTIVE,
        ]);

        Livewire::actingAs($user)
            ->test(ListCurricula::class)
            ->callAction('copyFromPreviousYear', ['source_academic_year_id' => $sourceYear->id]);

        $this->assertSame(2, $existingTarget->fresh()->weekly_hours);
        $this->assertSame('elective', $existingTarget->fresh()->type);
    }

    public function test_copying_from_an_empty_source_year_is_rejected_with_a_clear_message(): void
    {
        $user = $this->userWithRole('admin');
        $sourceYear = AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);
        $this->makeYear();

        Livewire::actingAs($user)
            ->test(ListCurricula::class)
            ->callAction('copyFromPreviousYear', ['source_academic_year_id' => $sourceYear->id])
            ->assertNotified(__('curriculum.copy_source_empty'));

        $this->assertDatabaseCount('curricula', 0);
    }

    public function test_the_copy_action_is_hidden_when_no_active_year_exists(): void
    {
        $user = $this->userWithRole('admin');
        AcademicYear::create([
            'name' => '2025 / 2026', 'start_date' => '2025-09-01', 'end_date' => '2026-05-31', 'is_active' => false,
        ]);

        Livewire::actingAs($user)
            ->test(ListCurricula::class)
            ->assertActionHidden('copyFromPreviousYear');
    }

    public function test_admin_can_see_the_copy_action_when_an_active_year_exists(): void
    {
        $user = $this->userWithRole('admin');
        $this->makeYear();

        Livewire::actingAs($user)
            ->test(ListCurricula::class)
            ->assertActionVisible('copyFromPreviousYear');
    }
}
