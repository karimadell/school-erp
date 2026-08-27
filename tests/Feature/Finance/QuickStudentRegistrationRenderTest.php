<?php

namespace Tests\Feature\Finance;

use App\Models\Grade;
use App\Models\AcademicYear;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuickStudentRegistrationRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_loads_grades_by_existing_level_then_id_without_grades_order_column(): void
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('accountant');

        $stage = Stage::create(['name' => 'Средняя школа', 'order' => 1, 'is_active' => true]);
        Grade::forceCreate(['stage_id' => $stage->id, 'name' => '10 класс', 'level' => 10]);
        $grade = Grade::forceCreate(['stage_id' => $stage->id, 'name' => '2 класс', 'level' => 2]);
        SchoolClass::create(['grade_id' => $grade->id, 'code' => '2-А', 'name_ar' => '2-A', 'name_ru' => '2-А', 'is_active' => true]);
        AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очное обучение', 'is_active' => true]);
        foreach ([
            ['Регистрационный взнос', Fee::CATEGORY_REGISTRATION], ['Обучение', Fee::CATEGORY_TUITION],
            ['Транспорт', Fee::CATEGORY_TRANSPORT], ['Питание', Fee::CATEGORY_FOOD], ['Школьная форма', Fee::CATEGORY_UNIFORM],
        ] as [$name, $category]) {
            Fee::create(['name_ru' => $name, 'category' => $category, 'amount' => 100, 'is_active' => true]);
        }

        $this->assertTrue(Schema::hasColumn('grades', 'level'));
        $this->assertFalse(Schema::hasColumn('grades', 'order'));

        $this->actingAs($user)
            ->get(route('dashboard.quick-registration.create'))
            ->assertOk()
            ->assertSeeInOrder(['2 класс', '10 класс'])
            ->assertSee('2026/2027')->assertSee('Очное обучение')->assertSee('Регистрационный взнос')
            ->assertSee('Обучение')->assertSee('Транспорт')->assertSee('Питание')->assertSee('Школьная форма')
            ->assertSee('Общая стоимость')->assertSee('Подтвердить оплату и завершить регистрацию')->assertSee('EGP')
            ->assertSee('name="student_last_name_ru"', false)
            ->assertSee('name="student_first_name_ru"', false)
            ->assertSee('name="student_patronymic_ru"', false)
            ->assertDontSee('student_name_en')->assertDontSee('name_ar');
    }
}
