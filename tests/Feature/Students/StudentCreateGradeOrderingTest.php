<?php

namespace Tests\Feature\Students;

use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentCreateGradeOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_student_create_orders_classes_by_numeric_grade_level(): void
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        $stage = Stage::create(['name' => 'Школа', 'order' => 1, 'is_active' => true]);

        foreach ([0, 1, 10, 11, 2, 3, 4, 5, 6, 7, 8, 9] as $level) {
            $grade = Grade::forceCreate([
                'stage_id' => $stage->id,
                'name' => $level.' КЛАСС',
                'level' => $level,
            ]);
            SchoolClass::create([
                'grade_id' => $grade->id,
                'code' => (string) $level,
                'name_ru' => $level.' КЛАСС',
                'name_ar' => $level.' КЛАСС',
                'is_active' => true,
            ]);
        }

        $this->actingAs($user)
            ->get('/dashboard/students/create')
            ->assertOk()
            ->assertSeeInOrder(array_map(
                fn (int $level): string => $level.' КЛАСС',
                range(0, 11),
            ));
    }

    public function test_data_repair_maps_only_recognizable_russian_grade_names_idempotently(): void
    {
        $stage = Stage::create(['name' => 'Школа', 'order' => 1, 'is_active' => true]);
        $recognized = Grade::forceCreate(['stage_id' => $stage->id, 'name' => '10  класс', 'level' => null]);
        $unknown = Grade::forceCreate(['stage_id' => $stage->id, 'name' => 'Подготовительный', 'level' => null]);
        $migration = require database_path('migrations/2026_08_04_120000_repair_numeric_grade_levels.php');

        $migration->up();
        $migration->up();

        $this->assertSame(10, $recognized->fresh()->level);
        $this->assertNull($unknown->fresh()->level);
        $this->assertSame('10  класс', $recognized->fresh()->name);
        $this->assertSame($recognized->id, $recognized->fresh()->id);
    }
}
