<?php

namespace Tests\Feature\Academic;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\EnrollmentModeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentModeManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->admin = User::factory()->create(['is_active'=>true]);
        $this->admin->assignRole('admin');
    }

    public function test_authorized_user_can_list_create_edit_and_deactivate_modes(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard.academic.enrollment-modes.index'))
            ->assertOk()->assertSee('Формы обучения')->assertSee('Добавить форму обучения');
        $this->actingAs($this->admin)->post(route('dashboard.academic.enrollment-modes.store'), [
            'name_ru'=>'  Семейная форма  ','short_name_ru'=>'Семейная','code'=>' Family Study ',
            'display_order'=>4,'is_active'=>1,'description'=>'Индивидуальный график',
        ])->assertRedirect(route('dashboard.academic.enrollment-modes.index'));
        $mode=EnrollmentMode::sole();
        $this->assertSame('family_study',$mode->code);
        $this->assertSame(4,$mode->display_order);
        $this->actingAs($this->admin)->put(route('dashboard.academic.enrollment-modes.update',$mode), [
            'name_ru'=>'Семейная форма','short_name_ru'=>'Семейная','code'=>'family_study',
            'display_order'=>2,'is_active'=>0,
        ])->assertRedirect();
        $this->assertFalse($mode->fresh()->is_active);
        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn($route)=>in_array('DELETE',$route->methods()) && $route->uri()==='dashboard/academic/enrollment-modes/{enrollmentMode}'));
    }

    public function test_duplicate_code_is_rejected_in_russian(): void
    {
        EnrollmentMode::create(['name_ru'=>'Очная','code'=>'full_time']);
        $this->actingAs($this->admin)->post(route('dashboard.academic.enrollment-modes.store'), [
            'name_ru'=>'Другая','code'=>'FULL TIME','display_order'=>0,'is_active'=>1,
        ])->assertSessionHasErrors('code');
        $this->assertContains('Форма обучения с таким кодом уже существует.',session('errors')->get('code'));
    }

    public function test_quick_registration_uses_only_ordered_active_modes_and_selection_rules(): void
    {
        $late=EnrollmentMode::create(['name_ru'=>'Вторая','code'=>'second','display_order'=>20,'is_active'=>true]);
        $first=EnrollmentMode::create(['name_ru'=>'Первая','code'=>'first','display_order'=>1,'is_active'=>true]);
        EnrollmentMode::create(['name_ru'=>'Скрытая','code'=>'hidden','display_order'=>0,'is_active'=>false]);
        $response=$this->actingAs($this->admin)->get(route('dashboard.quick-registration.create'));
        $response->assertOk()->assertSeeInOrder(['Первая','Вторая'])->assertDontSee('Скрытая')
            ->assertDontSee('value="'.$first->id.'" selected',false)->assertDontSee('value="'.$late->id.'" selected',false);

        $late->update(['is_active'=>false]);
        $this->actingAs($this->admin)->get(route('dashboard.quick-registration.create'))
            ->assertSee('value="'.$first->id.'" selected',false);
    }

    public function test_no_active_mode_shows_warning_and_admin_setup_link_and_blocks_post(): void
    {
        EnrollmentMode::create(['name_ru'=>'Закрытая','code'=>'closed','is_active'=>false]);
        $this->actingAs($this->admin)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->assertSee('Формы обучения не настроены.')
            ->assertSee('Настроить формы обучения')
            ->assertSee(route('dashboard.academic.enrollment-modes.index'));
        $this->actingAs($this->admin)->post(route('dashboard.quick-registration.store'),[])
            ->assertSessionHasErrors('enrollment_mode_id');
    }

    public function test_historical_enrollment_remains_readable_after_deactivation(): void
    {
        $mode=EnrollmentMode::create(['name_ru'=>'Историческая форма','code'=>'historic','is_active'=>true]);
        [$year,$stage,$grade,$class]=$this->structure();
        $student=Student::create(['name'=>'Иванов Иван']);
        Enrollment::create(['student_id'=>$student->id,'academic_year_id'=>$year->id,'enrollment_mode_id'=>$mode->id,'stage_id'=>$stage->id,'grade_id'=>$grade->id,'class_id'=>$class->id,'academic_year'=>$year->name,'enrollment_date'=>'2026-08-01','enrolled_at'=>'2026-08-01','status'=>'active','is_active'=>true]);
        $mode->update(['is_active'=>false]);
        $this->actingAs($this->admin)->get(route('dashboard.school-enrollment.index'))
            ->assertOk()->assertSee('Историческая форма');
    }

    public function test_e1_uses_the_same_active_mode_source(): void
    {
        $active=EnrollmentMode::create(['name_ru'=>'Очная форма','code'=>'full_time','display_order'=>1,'is_active'=>true]);
        EnrollmentMode::create(['name_ru'=>'Закрытая форма','code'=>'closed','is_active'=>false]);
        $this->actingAs($this->admin)->get(route('dashboard.school-enrollment.create'))
            ->assertOk()->assertSee($active->name_ru)->assertDontSee('Закрытая форма')
            ->assertSee('value="'.$active->id.'" selected',false);
    }

    public function test_only_approved_active_administrative_roles_can_manage_modes(): void
    {
        foreach (['super-admin','principal','admin','school-admin'] as $role) {
            $user=User::factory()->create(['is_active'=>true]); $user->assignRole($role);
            $this->actingAs($user)->get(route('dashboard.academic.enrollment-modes.index'))->assertOk();
        }
        foreach (['accountant','reception','teacher'] as $role) {
            $user=User::factory()->create(['is_active'=>true]); $user->assignRole($role);
            $response=$this->actingAs($user)->get(route('dashboard.academic.enrollment-modes.index'));
            $role==='teacher' ? $response->assertRedirect('/login') : $response->assertForbidden();
        }
        $none=User::factory()->create(['is_active'=>true]);
        $this->actingAs($none)->get(route('dashboard.academic.enrollment-modes.index'))->assertRedirect('/login');
        $disabled=User::factory()->create(['is_active'=>false]); $disabled->assignRole('admin');
        $this->actingAs($disabled)->get(route('dashboard.academic.enrollment-modes.index'))->assertRedirect('/login');
    }

    public function test_default_seeder_is_idempotent_and_never_overwrites_existing_data(): void
    {
        // A: empty table -> the seeder creates exactly the four canonical
        // codes, with the approved defaults.
        (new EnrollmentModeSeeder)->run();
        (new EnrollmentModeSeeder)->run(); // B: rerun stays idempotent, no duplicates.

        $this->assertDatabaseCount('enrollment_modes', 4);
        $this->assertDatabaseHas('enrollment_modes', [
            'code' => 'full_time', 'name_ru' => 'Очная форма обучения', 'short_name_ru' => 'Очная',
            'display_order' => 0, 'is_active' => true,
        ]);
        $this->assertDatabaseHas('enrollment_modes', [
            'code' => 'family', 'name_ru' => 'Семейная форма обучения', 'short_name_ru' => 'Семейная',
            'display_order' => 1, 'is_active' => false,
        ]);
        $this->assertDatabaseHas('enrollment_modes', [
            'code' => 'external', 'name_ru' => 'Экстернат', 'short_name_ru' => 'Экстернат',
            'display_order' => 2, 'is_active' => false,
        ]);
        $this->assertDatabaseHas('enrollment_modes', [
            'code' => 'no_enrollment', 'name_ru' => 'Без зачисления', 'short_name_ru' => 'Без зачисления',
            'display_order' => 3, 'is_active' => false,
        ]);
        foreach (['full_time', 'family', 'external', 'no_enrollment'] as $code) {
            $this->assertNull(EnrollmentMode::where('code', $code)->value('name_en'));
            $this->assertNull(EnrollmentMode::where('code', $code)->value('name_ar'));
        }

        // A pre-existing custom/non-canonical mode must never be overwritten
        // or removed, and the seeder must still add the four canonical rows
        // alongside it (5 total) rather than short-circuiting because the
        // table is non-empty.
        EnrollmentMode::query()->delete();
        EnrollmentMode::create(['name_ru' => 'Школьная форма', 'code' => 'school_defined', 'is_active' => false]);
        (new EnrollmentModeSeeder)->run();
        $this->assertDatabaseCount('enrollment_modes', 5);
        $this->assertDatabaseHas('enrollment_modes', ['code' => 'school_defined', 'is_active' => false]);
        $this->assertDatabaseHas('enrollment_modes', ['code' => 'full_time']);
        $this->assertDatabaseHas('enrollment_modes', ['code' => 'family']);
        $this->assertDatabaseHas('enrollment_modes', ['code' => 'external']);
        $this->assertDatabaseHas('enrollment_modes', ['code' => 'no_enrollment']);
    }

    public function test_seeder_preserves_an_existing_full_times_operator_customized_values(): void
    {
        // C: an existing canonical row (any ID, any operator-edited values)
        // must survive the seeder completely untouched — only the three
        // still-missing canonical codes get created alongside it.
        $existing = EnrollmentMode::create([
            'code' => 'full_time', 'name_ru' => 'Очная (изменено оператором)', 'short_name_ru' => 'Оч.',
            'display_order' => 42, 'is_active' => false, 'description' => 'Оператор поменял описание',
        ]);

        (new EnrollmentModeSeeder)->run();

        $fresh = $existing->fresh();
        $this->assertSame($existing->id, $fresh->id);
        $this->assertSame('Очная (изменено оператором)', $fresh->name_ru);
        $this->assertSame('Оч.', $fresh->short_name_ru);
        $this->assertSame(42, $fresh->display_order);
        $this->assertFalse($fresh->is_active);
        $this->assertSame('Оператор поменял описание', $fresh->description);

        $this->assertDatabaseCount('enrollment_modes', 4);
        $this->assertDatabaseHas('enrollment_modes', ['code' => 'family']);
        $this->assertDatabaseHas('enrollment_modes', ['code' => 'external']);
        $this->assertDatabaseHas('enrollment_modes', ['code' => 'no_enrollment']);
    }

    public function test_seeder_preserves_an_unknown_test_mode(): void
    {
        // D: a non-canonical row (e.g. a historical UAT test mode) is never
        // read, matched, or written by this seeder — it is identified
        // purely by iterating the four canonical codes, never by scanning
        // existing rows.
        $unknown = EnrollmentMode::create([
            'code' => 'uat_test', 'name_ru' => 'Тестовая форма обучения — изменено', 'short_name_ru' => 'Тест',
            'display_order' => 99, 'is_active' => false,
        ]);

        (new EnrollmentModeSeeder)->run();

        $fresh = $unknown->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($unknown->id, $fresh->id);
        $this->assertSame('uat_test', $fresh->code);
        $this->assertSame('Тестовая форма обучения — изменено', $fresh->name_ru);
        $this->assertSame('Тест', $fresh->short_name_ru);
        $this->assertFalse($fresh->is_active);
        $this->assertSame(99, $fresh->display_order);

        $this->assertDatabaseCount('enrollment_modes', 5);
        $this->assertDatabaseHas('enrollment_modes', ['code' => 'full_time']);
        $this->assertDatabaseHas('enrollment_modes', ['code' => 'family']);
        $this->assertDatabaseHas('enrollment_modes', ['code' => 'external']);
        $this->assertDatabaseHas('enrollment_modes', ['code' => 'no_enrollment']);
    }

    public function test_seeder_does_not_touch_a_historically_referenced_unknown_mode(): void
    {
        // E: an unknown mode that already has real Enrollment history must
        // remain exactly as-is, and the referencing Enrollment must keep
        // pointing at the exact same mode id.
        $unknown = EnrollmentMode::create(['code' => 'uat_test', 'name_ru' => 'Тестовая форма', 'is_active' => false]);
        [$year, $stage, $grade, $class] = $this->structure();
        $student = Student::create(['name' => 'Иванов Иван']);
        $enrollment = Enrollment::create([
            'student_id' => $student->id, 'academic_year_id' => $year->id, 'enrollment_mode_id' => $unknown->id,
            'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id,
            'academic_year' => $year->name, 'enrollment_date' => '2026-08-01', 'enrolled_at' => '2026-08-01',
            'status' => 'active', 'is_active' => true,
        ]);

        (new EnrollmentModeSeeder)->run();

        $this->assertSame($unknown->id, $enrollment->fresh()->enrollment_mode_id);
        $this->assertSame('uat_test', $unknown->fresh()->code);
        $this->assertSame('Тестовая форма', $unknown->fresh()->name_ru);
    }

    public function test_seeder_never_mutates_existing_enrollment_rows(): void
    {
        // F + G: the seeder only ever writes to enrollment_modes — every
        // existing Enrollment row (including one with a NULL mode) must
        // come out byte-for-byte identical, never reassigned.
        $mode = EnrollmentMode::create(['code' => 'full_time', 'name_ru' => 'Очная форма обучения', 'is_active' => true]);
        [$year, $stage, $grade, $class] = $this->structure();
        $studentA = Student::create(['name' => 'Студент А']);
        $studentB = Student::create(['name' => 'Студент Б']);

        $withMode = Enrollment::create([
            'student_id' => $studentA->id, 'academic_year_id' => $year->id, 'enrollment_mode_id' => $mode->id,
            'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id,
            'academic_year' => $year->name, 'enrollment_date' => '2026-08-01', 'enrolled_at' => '2026-08-01',
            'status' => 'active', 'is_active' => true,
        ]);
        $withNullMode = Enrollment::create([
            'student_id' => $studentB->id, 'academic_year_id' => $year->id, 'enrollment_mode_id' => null,
            'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id,
            'academic_year' => $year->name, 'enrollment_date' => '2026-08-01', 'enrolled_at' => '2026-08-01',
            'status' => 'active', 'is_active' => true,
        ]);

        $before = Enrollment::query()->orderBy('id')->get()->toArray();

        (new EnrollmentModeSeeder)->run();

        $this->assertSame($before, Enrollment::query()->orderBy('id')->get()->toArray());
        $this->assertSame($mode->id, $withMode->fresh()->enrollment_mode_id);
        $this->assertNull($withNullMode->fresh()->enrollment_mode_id);
    }

    public function test_new_canonical_modes_are_seeded_inactive_and_excluded_from_the_active_selector(): void
    {
        // H: family/external/no_enrollment must not be operator-selectable
        // yet. Verified directly against the same scope Quick Registration
        // uses, without touching any production selector/UI in this PR.
        (new EnrollmentModeSeeder)->run();

        $activeCodes = EnrollmentMode::active()->ordered()->pluck('code');

        $this->assertTrue($activeCodes->contains('full_time'));
        $this->assertFalse($activeCodes->contains('family'));
        $this->assertFalse($activeCodes->contains('external'));
        $this->assertFalse($activeCodes->contains('no_enrollment'));

        $this->assertFalse(EnrollmentMode::where('code', 'family')->value('is_active'));
        $this->assertFalse(EnrollmentMode::where('code', 'external')->value('is_active'));
        $this->assertFalse(EnrollmentMode::where('code', 'no_enrollment')->value('is_active'));
    }

    public function test_seeder_does_not_touch_finance_tables(): void
    {
        // I: the seeder writes only to enrollment_modes.
        (new EnrollmentModeSeeder)->run();

        $this->assertDatabaseCount('fees', 0);
        $this->assertDatabaseCount('fee_prices', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    private function structure(): array
    {
        $year=AcademicYear::create(['name'=>'2026/2027','start_date'=>'2026-08-01','end_date'=>'2027-06-30','is_active'=>true]);
        $stage=Stage::create(['name'=>'Начальная','order'=>1,'is_active'=>true]);
        $grade=Grade::forceCreate(['name'=>'1 класс','stage_id'=>$stage->id,'level'=>1]);
        $class=SchoolClass::create(['grade_id'=>$grade->id,'code'=>'А','name_ru'=>'1-А','name_ar'=>'A','is_active'=>true]);
        return [$year,$stage,$grade,$class];
    }
}
