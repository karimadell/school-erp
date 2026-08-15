<?php

namespace Tests\Feature;

use App\Filament\Resources\ClassResource\Pages\ListClasses;
use App\Filament\Resources\ClassResource;
use App\Filament\Resources\TeacherAssignments\TeacherAssignmentResource;
use App\Filament\Resources\TeacherAssignments\Pages\ListTeacherAssignments;
use App\Filament\Resources\TeacherSalaries\TeacherSalaryResource;
use App\Filament\Resources\TeacherSalaries\Pages\CreateTeacherSalary;
use App\Filament\Resources\TeacherSalaries\Pages\ListTeacherSalaries;
use App\Models\Teacher;
use App\Models\TeacherSalary;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UatResourceLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_assignment_and_class_lists_are_fully_localized_in_russian(): void
    {
        $user = $this->admin();
        app()->setLocale('ru');

        $this->assertSame('Зарплаты учителей', TeacherSalaryResource::getNavigationLabel());
        $this->assertSame('Назначения учителей', TeacherAssignmentResource::getNavigationLabel());
        $this->assertSame('Классы', ClassResource::getNavigationLabel());

        Livewire::actingAs($user)->test(ListTeacherSalaries::class)
            ->assertSuccessful()
            ->assertSee('Начислений зарплаты пока нет')
            ->assertDontSee('Teacher salaries')
            ->assertDontSee('Base salary');

        Livewire::actingAs($user)->test(ListTeacherAssignments::class)
            ->assertSuccessful()
            ->assertSee('Назначений учителей пока нет')
            ->assertDontSee('Teacher Assignments');

        Livewire::actingAs($user)->test(ListClasses::class)
            ->assertSuccessful()
            ->assertSee('Статус')
            ->assertSee('Классов пока нет')
            ->assertDontSee('Is active');
    }

    public function test_teacher_salary_create_workflow_uses_russian_labels_and_calculates_net_salary(): void
    {
        $user = $this->admin();
        $teacher = Teacher::create([
            'first_name' => 'Тестовый учитель',
            'last_name' => 'УАТ-Проверка',
            'email' => 'salary.ui.uat@example.invalid',
            'is_active' => true,
        ]);
        app()->setLocale('ru');

        Livewire::actingAs($user)->test(CreateTeacherSalary::class)
            ->assertSuccessful()
            ->assertSee('Учитель')
            ->assertSee('Базовая зарплата')
            ->assertSee('Премия')
            ->assertSee('Удержания')
            ->assertSee('Зарплата к выплате')
            ->assertSee('Месяц начисления')
            ->fillForm([
                'teacher_id' => $teacher->id,
                'base_salary' => 18000,
                'bonus' => 1200,
                'deductions' => 300,
                'salary_month' => '2026-07-01',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(18900.0, (float) TeacherSalary::firstOrFail()->net_salary);
    }

    public function test_new_resource_translation_keys_are_complete_in_all_supported_locales(): void
    {
        $keys = [
            'teacher_salary.navigation', 'teacher_salary.model', 'teacher_salary.models',
            'teacher_salary.teacher', 'teacher_salary.base_salary', 'teacher_salary.bonus',
            'teacher_salary.deductions', 'teacher_salary.net_salary', 'teacher_salary.month',
            'teacher_salary.empty_heading', 'teacher_salary.empty_description',
            'teacher_assignment.navigation', 'teacher_assignment.model', 'teacher_assignment.models',
            'teacher_assignment.fields.teacher', 'teacher_assignment.fields.class',
            'teacher_assignment.fields.subject', 'teacher_assignment.fields.academic_year',
            'teacher_assignment.empty_heading', 'teacher_assignment.empty_description',
            'classes.status', 'classes.empty_heading', 'classes.empty_description',
        ];

        foreach (['ru', 'en', 'ar'] as $locale) {
            foreach ($keys as $key) {
                $translation = trans($key, [], $locale);
                $this->assertNotSame($key, $translation, "Missing {$locale} translation for {$key}");
                $this->assertNotSame('', trim($translation), "Empty {$locale} translation for {$key}");
            }
        }
    }

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
