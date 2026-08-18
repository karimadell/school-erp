<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AcademicYearUnlock;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Quarter;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicCoreUatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function year(array $overrides = []): AcademicYear
    {
        return AcademicYear::create(array_merge([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01',
            'end_date' => '2027-06-30', 'is_active' => true,
        ], $overrides));
    }

    /** @return array{Stage, Grade, SchoolClass} */
    private function structure(string $suffix = 'A'): array
    {
        $stage = Stage::create(['name' => "Stage {$suffix}", 'order' => 1, 'is_active' => true]);
        $grade = Grade::create(['stage_id' => $stage->id, 'name' => "Grade {$suffix}"]);
        $class = SchoolClass::create([
            'grade_id' => $grade->id, 'code' => $suffix,
            'name_ru' => "Класс {$suffix}", 'name_ar' => "Class {$suffix}",
            'is_active' => true,
        ]);

        return [$stage, $grade, $class];
    }

    public function test_academic_year_rejects_equal_or_reversed_dates(): void
    {
        foreach (['2026-09-01', '2026-08-31'] as $endDate) {
            $this->actingAs($this->user('school-admin'))->post(route('dashboard.academic-years.store'), [
                'name' => 'Invalid', 'start_date' => '2026-09-01', 'end_date' => $endDate,
            ])->assertSessionHasErrors('end_date');
        }

        $this->assertDatabaseMissing('academic_years', ['name' => 'Invalid']);
    }

    public function test_locked_historical_year_cannot_be_silently_edited_but_can_be_activated_unchanged(): void
    {
        $admin = $this->user('school-admin');
        $historical = $this->year([
            'name' => '2020 / 2021', 'start_date' => '2020-09-01',
            'end_date' => '2021-06-30', 'is_active' => false,
        ]);

        $this->actingAs($admin)->put(route('dashboard.academic-years.update', $historical), [
            'name' => 'Changed history', 'start_date' => '2020-09-01',
            'end_date' => '2021-06-30', 'is_active' => 0,
        ])->assertSessionHasErrors('academic_year_lock');
        $this->assertSame('2020 / 2021', $historical->fresh()->name);

        $this->actingAs($admin)->put(route('dashboard.academic-years.update', $historical), [
            'name' => '2020 / 2021', 'start_date' => '2020-09-01',
            'end_date' => '2021-06-30', 'is_active' => 1,
        ])->assertRedirect(route('dashboard.academic-years.index'));
        $this->assertTrue($historical->fresh()->is_active);
    }

    public function test_quarters_are_year_scoped_and_valid_dates_can_be_created_and_updated(): void
    {
        $admin = $this->user('school-admin');
        $year = $this->year();

        $this->actingAs($admin)->post(route('dashboard.academic-years.quarters.store', $year), [
            'name' => 'Первая четверть', 'order' => 1,
            'start_date' => '2026-09-01', 'end_date' => '2026-10-31',
        ])->assertRedirect(route('dashboard.academic-years.quarters.index', $year));

        $quarter = Quarter::firstOrFail();
        $this->assertSame($year->id, $quarter->academic_year_id);

        $this->actingAs($admin)->put(route('dashboard.academic-years.quarters.update', [$year, $quarter]), [
            'name' => '1-я четверть', 'order' => 1,
            'start_date' => '2026-09-01', 'end_date' => '2026-11-01',
        ])->assertRedirect(route('dashboard.academic-years.quarters.index', $year));
        $this->assertSame('1-я четверть', $quarter->fresh()->name);
    }

    public function test_future_inactive_year_can_be_prepared_without_changing_the_active_year(): void
    {
        $admin = $this->user('school-admin');
        $activeYear = $this->year([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01',
            'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        $futureYear = $this->year([
            'name' => '2027 / 2028', 'start_date' => '2027-09-01',
            'end_date' => '2028-06-30', 'is_active' => false,
        ]);

        $this->actingAs($admin)->post(route('dashboard.academic-years.quarters.store', $futureYear), [
            'name' => 'Первая четверть', 'order' => 1,
            'start_date' => '2027-09-01', 'end_date' => '2027-10-31',
        ])->assertRedirect(route('dashboard.academic-years.quarters.index', $futureYear));

        $quarter = $futureYear->quarters()->firstOrFail();
        $this->actingAs($admin)->put(route('dashboard.academic-years.quarters.update', [$futureYear, $quarter]), [
            'name' => 'Подготовленная четверть', 'order' => 1,
            'start_date' => '2027-09-01', 'end_date' => '2027-11-01',
        ])->assertRedirect(route('dashboard.academic-years.quarters.index', $futureYear));

        $this->assertFalse($futureYear->fresh()->is_active);
        $this->assertTrue($activeYear->fresh()->is_active);
        $this->assertSame('Подготовленная четверть', $quarter->fresh()->name);
    }

    public function test_historical_year_requires_unlock_before_quarters_can_be_prepared(): void
    {
        $admin = $this->user('school-admin');
        $historical = $this->year([
            'name' => '2020 / 2021', 'start_date' => '2020-09-01',
            'end_date' => '2021-06-30', 'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard.academic-years.quarters.create', $historical))
            ->assertForbidden();

        AcademicYearUnlock::create([
            'academic_year_id' => $historical->id,
            'reason' => 'Исправление исторических данных',
            'unlocked_by' => $admin->id,
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($admin)->post(route('dashboard.academic-years.quarters.store', $historical), [
            'name' => 'Архивная четверть', 'order' => 1,
            'start_date' => '2020-09-01', 'end_date' => '2020-10-31',
        ])->assertRedirect(route('dashboard.academic-years.quarters.index', $historical));

        $quarter = $historical->quarters()->firstOrFail();
        $this->actingAs($admin)->put(route('dashboard.academic-years.quarters.update', [$historical, $quarter]), [
            'name' => 'Исправленная архивная четверть', 'order' => 1,
            'start_date' => '2020-09-01', 'end_date' => '2020-11-01',
        ])->assertRedirect(route('dashboard.academic-years.quarters.index', $historical));

        $this->assertSame('Исправленная архивная четверть', $quarter->fresh()->name);
    }

    public function test_quarter_rejects_invalid_outside_year_and_overlapping_dates(): void
    {
        $admin = $this->user('school-admin');
        $year = $this->year();
        Quarter::create([
            'academic_year_id' => $year->id, 'name' => 'Q1', 'order' => 1,
            'start_date' => '2026-09-01', 'end_date' => '2026-10-31',
        ]);

        $base = route('dashboard.academic-years.quarters.store', $year);
        $this->actingAs($admin)->post($base, [
            'name' => 'Bad order', 'order' => 2,
            'start_date' => '2026-11-01', 'end_date' => '2026-11-01',
        ])->assertSessionHasErrors('end_date');
        $this->actingAs($admin)->post($base, [
            'name' => 'Outside', 'order' => 2,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
        ])->assertSessionHasErrors('start_date');
        $this->actingAs($admin)->post($base, [
            'name' => 'Overlap', 'order' => 2,
            'start_date' => '2026-10-15', 'end_date' => '2026-12-01',
        ])->assertSessionHasErrors('start_date');

        $this->assertDatabaseCount('quarters', 1);
    }

    public function test_quarter_routes_require_permission_and_locked_year_is_read_only(): void
    {
        $year = $this->year();
        $this->actingAs($this->user('accountant'))
            ->get(route('dashboard.academic-years.quarters.index', $year))->assertForbidden();
        $this->actingAs($this->user('principal'))
            ->get(route('dashboard.academic-years.quarters.index', $year))->assertOk();

        $teacher = $this->user('teacher');
        $this->actingAs($teacher)
            ->get(route('dashboard.academic-years.quarters.index', $year))
            ->assertRedirect(route('login'));

        $year = $this->year([
            'name' => '2020 / 2021', 'start_date' => '2020-09-01',
            'end_date' => '2021-06-30', 'is_active' => false,
        ]);
        $this->actingAs($this->user('school-admin'))
            ->get(route('dashboard.academic-years.quarters.create', $year))->assertForbidden();
    }

    public function test_ordinary_enrollment_rejects_forged_hierarchy_and_accepts_valid_hierarchy(): void
    {
        $user = $this->user('school-admin');
        $year = $this->year();
        [$stage, $grade, $class] = $this->structure('A');
        [$otherStage, $otherGrade, $otherClass] = $this->structure('B');
        $student = Student::forceCreate(['name' => 'Иван Иванов']);
        $route = route('dashboard.enrollments.store', $student);
        $payload = [
            'academic_year_id' => $year->id, 'stage_id' => $stage->id,
            'grade_id' => $grade->id, 'class_id' => $class->id, 'status' => 'active',
        ];

        $this->actingAs($user)->post($route, array_merge($payload, ['grade_id' => $otherGrade->id]))
            ->assertSessionHasErrors('grade_id');
        $this->actingAs($user)->post($route, array_merge($payload, ['class_id' => $otherClass->id]))
            ->assertSessionHasErrors('class_id');
        $this->assertDatabaseCount('enrollments', 0);

        $this->actingAs($user)->post($route, $payload)
            ->assertRedirect(route('dashboard.students.show', $student));
        $this->assertDatabaseHas('enrollments', [
            'student_id' => $student->id, 'stage_id' => $stage->id,
            'grade_id' => $grade->id, 'class_id' => $class->id,
        ]);

        $enrollment = Enrollment::firstOrFail();
        $this->actingAs($user)->put(route('dashboard.enrollments.update', $enrollment), [
            'academic_year_id' => $year->id, 'stage_id' => $otherStage->id,
            'grade_id' => $otherGrade->id, 'class_id' => $class->id, 'status' => 'active',
        ])->assertSessionHasErrors('class_id');
        $this->assertSame($class->id, $enrollment->fresh()->class_id);
    }

    public function test_used_academic_structure_is_refused_without_cascading_history(): void
    {
        $admin = $this->user('school-admin');
        $year = $this->year();
        [$stage, $grade, $class] = $this->structure();
        $student = Student::forceCreate(['name' => 'Protected Student', 'class_id' => $class->id]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id, 'academic_year_id' => $year->id,
            'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id,
            'enrollment_date' => '2026-09-01', 'status' => 'active', 'is_active' => true,
        ]);

        $this->actingAs($admin)->delete(route('dashboard.classes.destroy', $class))
            ->assertSessionHasErrors('delete');
        $this->actingAs($admin)->delete(route('dashboard.grades.destroy', $grade))
            ->assertSessionHasErrors('delete');
        $this->actingAs($admin)->delete(route('dashboard.stages.destroy', $stage))
            ->assertSessionHasErrors('delete');
        $this->actingAs($admin)->delete(route('dashboard.academic-years.destroy', $year))
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);
        $this->assertDatabaseHas('classes', ['id' => $class->id]);
        $this->assertDatabaseHas('grades', ['id' => $grade->id]);
        $this->assertDatabaseHas('stages', ['id' => $stage->id]);
        $this->assertDatabaseHas('academic_years', ['id' => $year->id]);
    }

    public function test_unused_structure_and_unused_quarter_can_be_deleted(): void
    {
        $admin = $this->user('school-admin');
        $year = $this->year();
        $quarter = Quarter::create([
            'academic_year_id' => $year->id, 'name' => 'Temporary', 'order' => 1,
            'start_date' => '2026-09-01', 'end_date' => '2026-10-31',
        ]);
        [$stage, $grade, $class] = $this->structure();

        $this->actingAs($admin)->delete(route('dashboard.academic-years.quarters.destroy', [$year, $quarter]))
            ->assertRedirect(route('dashboard.academic-years.quarters.index', $year));
        $this->actingAs($admin)->delete(route('dashboard.classes.destroy', $class))->assertRedirect();
        $this->actingAs($admin)->delete(route('dashboard.grades.destroy', $grade))->assertRedirect();
        $this->actingAs($admin)->delete(route('dashboard.stages.destroy', $stage))->assertRedirect();

        $this->assertDatabaseCount('quarters', 0);
        $this->assertDatabaseCount('classes', 0);
        $this->assertDatabaseCount('grades', 0);
        $this->assertDatabaseCount('stages', 0);
    }

    public function test_main_erp_exposes_russian_academic_year_structure_and_quarter_paths(): void
    {
        $admin = $this->user('school-admin');
        $year = $this->year();

        $this->actingAs($admin)->get(route('dashboard.academic-years.index'))
            ->assertOk()->assertSee('Учебные годы')->assertSee('Четверти')
            ->assertSee('Активен');
        $this->actingAs($admin)->get(route('dashboard.stages.index'))
            ->assertOk()->assertSee('Ступени обучения');
        $this->actingAs($admin)->get(route('dashboard.academic-years.quarters.index', $year))
            ->assertOk()->assertSee('Четверти')->assertSee($year->name);
    }
}
