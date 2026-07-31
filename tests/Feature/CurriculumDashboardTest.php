<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\Grade;
use App\Models\Stage;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Custom Application Shell Migration — Curriculum Batch 1: classic
 * /dashboard routes for Curriculum, reusing the Curriculum model and
 * CurriculumPolicy (same policy the Filament resource uses, exercised in
 * CurriculumTest). 'manage curriculum' is admin-only (see
 * RolesAndPermissionsSeeder), so — unlike Academic Years — school-admin
 * and principal are also unauthorized here, not just accountant.
 */
class CurriculumDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithRole(string $role): User
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function makeYear(array $overrides = []): AcademicYear
    {
        return AcademicYear::create(array_merge([
            'name' => '2026 / 2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-05-31',
            'is_active' => false,
        ], $overrides));
    }

    protected function makeGrade(string $name = 'Grade 1'): Grade
    {
        $stage = Stage::create(['name' => 'Primary']);

        return Grade::create(['name' => $name, 'stage_id' => $stage->id]);
    }

    protected function makeSubject(string $nameRu = 'Математика'): Subject
    {
        return Subject::create(['code' => 'S-' . uniqid(), 'name_ar' => 'a', 'name_ru' => $nameRu]);
    }

    protected function makeCurriculum(array $overrides = []): Curriculum
    {
        return Curriculum::create(array_merge([
            'academic_year_id' => $this->makeYear()->id,
            'grade_id' => $this->makeGrade()->id,
            'subject_id' => $this->makeSubject()->id,
            'weekly_hours' => 3,
            'type' => Curriculum::TYPE_MANDATORY,
            'assessment_type' => Curriculum::ASSESSMENT_GRADE,
            'report_order' => null,
            'is_active' => true,
            'notes' => null,
        ], $overrides));
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization — 'manage curriculum' is admin-only
    |--------------------------------------------------------------------------
    */

    public function test_school_admin_cannot_view_the_index(): void
    {
        $user = $this->userWithRole('school-admin');

        $this->actingAs($user)
            ->get(route('dashboard.curricula.index'))
            ->assertForbidden();
    }

    public function test_principal_cannot_view_the_index(): void
    {
        $user = $this->userWithRole('principal');

        $this->actingAs($user)
            ->get(route('dashboard.curricula.index'))
            ->assertForbidden();
    }

    public function test_accountant_cannot_view_the_create_form(): void
    {
        $user = $this->userWithRole('accountant');

        $this->actingAs($user)
            ->get(route('dashboard.curricula.create'))
            ->assertForbidden();
    }

    public function test_school_admin_cannot_store(): void
    {
        $user = $this->userWithRole('school-admin');
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $subject = $this->makeSubject();

        $this->actingAs($user)
            ->post(route('dashboard.curricula.store'), [
                'academic_year_id' => $year->id,
                'grade_id' => $grade->id,
                'subject_id' => $subject->id,
                'weekly_hours' => 3,
                'type' => Curriculum::TYPE_MANDATORY,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('curricula', 0);
    }

    public function test_school_admin_cannot_update(): void
    {
        $user = $this->userWithRole('school-admin');
        $entry = $this->makeCurriculum();

        $this->actingAs($user)
            ->put(route('dashboard.curricula.update', $entry), [
                'academic_year_id' => $entry->academic_year_id,
                'grade_id' => $entry->grade_id,
                'subject_id' => $entry->subject_id,
                'weekly_hours' => 5,
                'type' => Curriculum::TYPE_MANDATORY,
            ])
            ->assertForbidden();

        $this->assertSame(3, $entry->fresh()->weekly_hours);
    }

    public function test_school_admin_cannot_delete(): void
    {
        $user = $this->userWithRole('school-admin');
        $entry = $this->makeCurriculum();

        $this->actingAs($user)
            ->delete(route('dashboard.curricula.destroy', $entry))
            ->assertForbidden();

        $this->assertDatabaseHas('curricula', ['id' => $entry->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    */

    public function test_super_admin_can_manage_curriculum_end_to_end(): void
    {
        $admin = $this->userWithRole('admin');
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $subject = $this->makeSubject();

        $this->actingAs($admin)
            ->get(route('dashboard.curricula.index'))
            ->assertSuccessful();

        $this->actingAs($admin)
            ->post(route('dashboard.curricula.store'), [
                'academic_year_id' => $year->id,
                'grade_id' => $grade->id,
                'subject_id' => $subject->id,
                'weekly_hours' => 4,
                'type' => Curriculum::TYPE_MANDATORY,
                'assessment_type' => Curriculum::ASSESSMENT_GRADE,
                'is_active' => true,
            ])
            ->assertRedirect(route('dashboard.curricula.index'));

        $entry = Curriculum::where('grade_id', $grade->id)->where('subject_id', $subject->id)->firstOrFail();
        $this->assertSame(4, $entry->weekly_hours);

        $this->actingAs($admin)
            ->put(route('dashboard.curricula.update', $entry), [
                'academic_year_id' => $year->id,
                'grade_id' => $grade->id,
                'subject_id' => $subject->id,
                'weekly_hours' => 6,
                'type' => Curriculum::TYPE_ELECTIVE,
                'assessment_type' => Curriculum::ASSESSMENT_PASS_FAIL,
                'report_order' => 10,
                'is_active' => false,
                'notes' => 'Обновлено',
            ])
            ->assertRedirect(route('dashboard.curricula.index'));

        $fresh = $entry->fresh();
        $this->assertSame(6, $fresh->weekly_hours);
        $this->assertSame(Curriculum::TYPE_ELECTIVE, $fresh->type);
        $this->assertSame(Curriculum::ASSESSMENT_PASS_FAIL, $fresh->assessment_type);
        $this->assertSame(10, $fresh->report_order);
        $this->assertFalse($fresh->is_active);
        $this->assertSame('Обновлено', $fresh->notes);

        $this->actingAs($admin)
            ->delete(route('dashboard.curricula.destroy', $entry))
            ->assertRedirect(route('dashboard.curricula.index'));

        $this->assertDatabaseMissing('curricula', ['id' => $entry->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    public function test_store_requires_all_fields(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('dashboard.curricula.store'), [])
            ->assertSessionHasErrors([
                'academic_year_id', 'grade_id', 'subject_id', 'weekly_hours', 'type',
                'assessment_type', 'is_active',
            ]);

        $this->assertDatabaseCount('curricula', 0);
    }

    public function test_weekly_hours_must_be_at_least_one(): void
    {
        $admin = $this->userWithRole('admin');
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $subject = $this->makeSubject();

        $this->actingAs($admin)
            ->post(route('dashboard.curricula.store'), [
                'academic_year_id' => $year->id,
                'grade_id' => $grade->id,
                'subject_id' => $subject->id,
                'weekly_hours' => 0,
                'type' => Curriculum::TYPE_MANDATORY,
            ])
            ->assertSessionHasErrors('weekly_hours');
    }

    public function test_type_must_be_one_of_the_valid_values(): void
    {
        $admin = $this->userWithRole('admin');
        $year = $this->makeYear();
        $grade = $this->makeGrade();
        $subject = $this->makeSubject();

        $this->actingAs($admin)
            ->post(route('dashboard.curricula.store'), [
                'academic_year_id' => $year->id,
                'grade_id' => $grade->id,
                'subject_id' => $subject->id,
                'weekly_hours' => 2,
                'type' => 'not-a-real-type',
            ])
            ->assertSessionHasErrors('type');
    }

    public function test_duplicate_academic_year_grade_subject_combination_is_rejected(): void
    {
        $admin = $this->userWithRole('admin');
        $existing = $this->makeCurriculum();

        $this->actingAs($admin)
            ->post(route('dashboard.curricula.store'), [
                'academic_year_id' => $existing->academic_year_id,
                'grade_id' => $existing->grade_id,
                'subject_id' => $existing->subject_id,
                'weekly_hours' => 2,
                'type' => Curriculum::TYPE_ELECTIVE,
            ])
            ->assertSessionHasErrors('academic_year_id');

        $this->assertDatabaseCount('curricula', 1);
    }

    public function test_updating_a_record_to_its_own_existing_combination_is_allowed(): void
    {
        $admin = $this->userWithRole('admin');
        $entry = $this->makeCurriculum();

        $this->actingAs($admin)
            ->put(route('dashboard.curricula.update', $entry), [
                'academic_year_id' => $entry->academic_year_id,
                'grade_id' => $entry->grade_id,
                'subject_id' => $entry->subject_id,
                'weekly_hours' => 7,
                'type' => Curriculum::TYPE_MANDATORY,
                'assessment_type' => Curriculum::ASSESSMENT_GRADE,
                'is_active' => true,
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(7, $entry->fresh()->weekly_hours);
    }

    /*
    |--------------------------------------------------------------------------
    | Rendering — empty state, list content, prefill, filters
    |--------------------------------------------------------------------------
    */

    public function test_index_shows_empty_state_when_there_are_no_entries(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('dashboard.curricula.index'))
            ->assertSuccessful()
            ->assertSee(__('curriculum.no_data'));
    }

    public function test_index_lists_existing_entries(): void
    {
        $admin = $this->userWithRole('admin');
        $grade = $this->makeGrade('Grade 5');
        $this->makeCurriculum(['grade_id' => $grade->id]);

        $this->actingAs($admin)
            ->get(route('dashboard.curricula.index'))
            ->assertSuccessful()
            ->assertSee('Grade 5');
    }

    public function test_index_does_not_render_a_translation_key_for_an_invalid_assessment_type(): void
    {
        $admin = $this->userWithRole('admin');
        $entry = $this->makeCurriculum();
        $entry->setAttribute('assessment_type', 'unexpected');

        $this->actingAs($admin);
        $this->view('dashboard.curricula.index', [
            'curricula' => new \Illuminate\Pagination\LengthAwarePaginator([$entry], 1, 15),
            'academicYears' => collect(),
            'grades' => collect(),
        ])
            ->assertSee('—')
            ->assertDontSee('curriculum.assessment_unexpected');
    }

    public function test_index_filters_by_academic_year(): void
    {
        $admin = $this->userWithRole('admin');

        $yearA = $this->makeYear(['name' => 'Year A']);
        $yearB = $this->makeYear(['name' => 'Year B']);

        // Subjects (unlike grades) aren't rendered in any filter <select>,
        // only inside table rows — a reliable marker for which rows made
        // it past the filter, without the dropdown's own option list
        // producing a false "still present" match.
        $this->makeCurriculum(['academic_year_id' => $yearA->id, 'subject_id' => $this->makeSubject('Subject A')->id]);
        $this->makeCurriculum(['academic_year_id' => $yearB->id, 'subject_id' => $this->makeSubject('Subject B')->id]);

        $response = $this->actingAs($admin)
            ->get(route('dashboard.curricula.index', ['academic_year_id' => $yearA->id]));

        $response->assertSuccessful();
        $response->assertSee('Subject A');
        $response->assertDontSee('Subject B');
    }

    public function test_index_filters_by_grade(): void
    {
        $admin = $this->userWithRole('admin');

        $gradeA = $this->makeGrade('Grade A');
        $gradeB = $this->makeGrade('Grade B');

        $this->makeCurriculum(['grade_id' => $gradeA->id, 'subject_id' => $this->makeSubject('Subject A')->id]);
        $this->makeCurriculum(['grade_id' => $gradeB->id, 'subject_id' => $this->makeSubject('Subject B')->id]);

        $response = $this->actingAs($admin)
            ->get(route('dashboard.curricula.index', ['grade_id' => $gradeA->id]));

        $response->assertSuccessful();
        $response->assertSee('Subject A');
        $response->assertDontSee('Subject B');
    }

    public function test_index_filters_by_type(): void
    {
        $admin = $this->userWithRole('admin');

        $this->makeCurriculum(['type' => Curriculum::TYPE_MANDATORY, 'subject_id' => $this->makeSubject('Mandatory Subject')->id]);
        $this->makeCurriculum(['type' => Curriculum::TYPE_ELECTIVE, 'subject_id' => $this->makeSubject('Elective Subject')->id]);

        $response = $this->actingAs($admin)
            ->get(route('dashboard.curricula.index', ['type' => Curriculum::TYPE_ELECTIVE]));

        $response->assertSuccessful();
        $response->assertSee('Elective Subject');
        $response->assertDontSee('Mandatory Subject');
    }

    public function test_index_paginates_beyond_fifteen_entries(): void
    {
        $admin = $this->userWithRole('admin');
        $year = $this->makeYear();

        for ($i = 0; $i < 16; $i++) {
            $this->makeCurriculum([
                'academic_year_id' => $year->id,
                'grade_id' => $this->makeGrade("Grade {$i}")->id,
                'subject_id' => $this->makeSubject("Subject {$i}")->id,
            ]);
        }

        $response = $this->actingAs($admin)->get(route('dashboard.curricula.index'));

        $response->assertSuccessful();
        $this->assertTrue($response->viewData('curricula')->hasPages());
    }

    public function test_edit_form_prefills_existing_values(): void
    {
        $admin = $this->userWithRole('admin');
        $entry = $this->makeCurriculum();

        $this->actingAs($admin)
            ->get(route('dashboard.curricula.edit', $entry))
            ->assertSuccessful()
            ->assertSee($entry->weekly_hours)
            ->assertSee('name="assessment_type"', false)
            ->assertSee('name="report_order"', false)
            ->assertSee('name="is_active"', false)
            ->assertSee('name="notes"', false);
    }

    public function test_create_form_renders_russian_metadata_fields_and_options(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('dashboard.curricula.create'))
            ->assertSuccessful()
            ->assertSee('Форма аттестации')
            ->assertSee('Оценка')
            ->assertSee('Зачёт')
            ->assertSee('Без оценивания')
            ->assertSee('Порядок в табеле')
            ->assertSee('Примечания');
    }

    public function test_metadata_validation_boundaries_and_invalid_values(): void
    {
        $admin = $this->userWithRole('admin');
        $year = $this->makeYear();
        $grade = $this->makeGrade();

        foreach ([1, 999, null] as $index => $reportOrder) {
            $subject = $this->makeSubject("Valid {$index}");
            $this->actingAs($admin)
                ->post(route('dashboard.curricula.store'), [
                    'academic_year_id' => $year->id,
                    'grade_id' => $grade->id,
                    'subject_id' => $subject->id,
                    'weekly_hours' => 2,
                    'type' => Curriculum::TYPE_MANDATORY,
                    'assessment_type' => Curriculum::ASSESSMENT_GRADE,
                    'report_order' => $reportOrder,
                    'is_active' => true,
                ])
                ->assertSessionDoesntHaveErrors();
        }

        foreach ([0, 1000] as $index => $reportOrder) {
            $this->actingAs($admin)
                ->post(route('dashboard.curricula.store'), [
                    'academic_year_id' => $year->id,
                    'grade_id' => $grade->id,
                    'subject_id' => $this->makeSubject("Invalid order {$index}")->id,
                    'weekly_hours' => 2,
                    'type' => Curriculum::TYPE_MANDATORY,
                    'assessment_type' => Curriculum::ASSESSMENT_GRADE,
                    'report_order' => $reportOrder,
                    'is_active' => true,
                ])
                ->assertSessionHasErrors('report_order');
        }

        $this->actingAs($admin)
            ->post(route('dashboard.curricula.store'), [
                'academic_year_id' => $year->id,
                'grade_id' => $grade->id,
                'subject_id' => $this->makeSubject('Invalid assessment')->id,
                'weekly_hours' => 2,
                'type' => Curriculum::TYPE_MANDATORY,
                'assessment_type' => 'invalid',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('assessment_type');

        $this->actingAs($admin)
            ->post(route('dashboard.curricula.store'), [
                'academic_year_id' => $year->id,
                'grade_id' => $grade->id,
                'subject_id' => $this->makeSubject('Long notes')->id,
                'weekly_hours' => 2,
                'type' => Curriculum::TYPE_MANDATORY,
                'assessment_type' => Curriculum::ASSESSMENT_GRADE,
                'is_active' => true,
                'notes' => str_repeat('a', 2001),
            ])
            ->assertSessionHasErrors('notes');
    }

    /*
    |--------------------------------------------------------------------------
    | Sidebar repoint
    |--------------------------------------------------------------------------
    */

    public function test_dashboard_sidebar_links_curriculum_to_the_classic_route_not_filament(): void
    {
        $admin = $this->userWithRole('admin');

        $response = $this->actingAs($admin)->get(route('dashboard.index'));

        $response->assertSuccessful();
        $response->assertSee(route('dashboard.curricula.index'), false);
    }
}
