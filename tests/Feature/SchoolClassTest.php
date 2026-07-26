<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolClassTest extends TestCase
{
    use RefreshDatabase;

    protected function makeGrade(): Grade
    {
        $stage = Stage::create(['name' => 'Primary', 'order' => 1, 'is_active' => true]);

        return Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
    }

    public function test_name_ar_is_mass_assignable(): void
    {
        // Regression test: classes.name_ar is NOT NULL in the database but
        // was missing from SchoolClass::$fillable, so any mass-assignment
        // create/update (as ClassController::store/update perform) either
        // silently dropped it or violated the NOT NULL constraint.
        $grade = $this->makeGrade();

        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'A',
            'name_ar' => 'فصل A',
            'name_ru' => 'Класс A',
            'capacity' => 25,
            'is_active' => true,
        ]);

        $this->assertSame('فصل A', $class->fresh()->name_ar);
    }

    public function test_class_can_be_created_via_controller(): void
    {
        $user = User::factory()->create();
        $grade = $this->makeGrade();

        $response = $this->actingAs($user)->post(route('dashboard.classes.store'), [
            'grade_id' => $grade->id,
            'code' => 'B',
            'name_ru' => 'Класс B',
            'capacity' => 25,
        ]);

        $response->assertRedirect(route('dashboard.classes.index'));

        $class = SchoolClass::where('code', 'B')->firstOrFail();
        $this->assertSame('Класс B', $class->name_ar);
        $this->assertSame('Класс B', $class->name_ru);
    }

    public function test_class_name_ar_can_be_updated_via_controller(): void
    {
        $user = User::factory()->create();
        $grade = $this->makeGrade();

        $class = SchoolClass::create([
            'grade_id' => $grade->id,
            'code' => 'C',
            'name_ar' => 'فصل C',
            'name_ru' => 'Класс C',
            'capacity' => 25,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->put(route('dashboard.classes.update', $class), [
            'grade_id' => $grade->id,
            'code' => 'C',
            'name_ru' => 'Класс C Updated',
            'capacity' => 30,
        ]);

        $response->assertRedirect(route('dashboard.classes.index'));

        $this->assertSame('Класс C Updated', $class->fresh()->name_ar);
    }
}
