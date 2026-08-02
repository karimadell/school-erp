<?php

namespace Tests\Feature\Finance;

use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;

class QuickStudentRegistrationStructureTest extends QuickRegistrationUxTestCase
{
    public function test_hierarchy_is_stage_grade_and_class_letter_with_old_values_preserved(): void
    {
        $structure = $this->structure();
        [, $stage, $grade, $class] = $structure;
        $otherStage = Stage::create(['name' => 'Средняя школа', 'order' => 2, 'is_active' => true]);
        $otherGrade = Grade::forceCreate(['name' => '5 класс', 'stage_id' => $otherStage->id, 'level' => 5]);
        SchoolClass::create(['grade_id' => $otherGrade->id, 'code' => 'Б', 'name_ru' => 'Б', 'name_ar' => 'B', 'is_active' => true]);
        $this->fee();

        $response = $this->actingAs($this->accountant)->withSession(['_old_input' => [
            'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id,
        ]])->get(route('dashboard.quick-registration.create'));

        $response->assertOk()->assertSee('Ступень *')->assertSee('Класс *')->assertSee('Буква класса *')
            ->assertDontSee('Параллель *')->assertSee('Сначала выберите ступень.')
            ->assertSee('Сначала выберите класс.')->assertSee('Классы не настроены.')
            ->assertSee('data-stage="'.$stage->id.'"', false)->assertSee('data-grade="'.$grade->id.'"', false)
            ->assertSee('value="'.$grade->id.'" data-stage="'.$stage->id.'" selected', false)
            ->assertSee('value="'.$class->id.'" data-grade="'.$grade->id.'" selected', false);
    }
}
