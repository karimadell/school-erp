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
        (new EnrollmentModeSeeder)->run(); (new EnrollmentModeSeeder)->run();
        $this->assertDatabaseCount('enrollment_modes',1);
        $this->assertDatabaseHas('enrollment_modes',['code'=>'full_time','name_ru'=>'Очная форма обучения','short_name_ru'=>'Очная','is_active'=>true]);
        EnrollmentMode::query()->delete();
        EnrollmentMode::create(['name_ru'=>'Школьная форма','code'=>'school_defined','is_active'=>false]);
        (new EnrollmentModeSeeder)->run();
        $this->assertDatabaseCount('enrollment_modes',1);
        $this->assertDatabaseHas('enrollment_modes',['code'=>'school_defined','is_active'=>false]);
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
