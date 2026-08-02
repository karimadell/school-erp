<?php

namespace Tests\Feature\Students;

use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RussianStudentNameTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $stage = Stage::create(['name' => 'Школа', 'is_active' => true]);
        $grade = Grade::create(['name' => '1 класс', 'stage_id' => $stage->id]);
        $this->class = SchoolClass::create([
            'grade_id' => $grade->id, 'code' => '1-А', 'name_ar' => '1-A',
            'name_ru' => '1-А', 'is_active' => true,
        ]);
    }

    public function test_full_name_is_normalized_composed_and_synchronized_to_legacy_name(): void
    {
        $student = Student::create([
            'last_name_ru' => '  Иванов  ', 'first_name_ru' => ' Иван   Иван ',
            'patronymic_ru' => ' Иванович ',
        ]);

        $this->assertSame('Иванов', $student->last_name_ru);
        $this->assertSame('Иван Иван', $student->first_name_ru);
        $this->assertSame('Иванов Иван Иван Иванович', $student->full_name);
        $this->assertSame($student->full_name, $student->name);
    }

    public function test_patronymic_is_optional_and_legacy_name_remains_a_safe_fallback(): void
    {
        $structured = Student::create(['last_name_ru' => 'Петрова', 'first_name_ru' => 'Анна']);
        $legacy = Student::forceCreate(['name' => 'Историческое Имя']);

        $this->assertSame('Петрова Анна', $structured->full_name);
        $this->assertSame('Историческое Имя', $legacy->full_name);
        $this->assertGreaterThan(0, $structured->profile_completion_percentage);
    }

    public function test_profile_forms_contain_only_structured_russian_name_inputs(): void
    {
        $student = Student::create([
            'class_id' => $this->class->id, 'last_name_ru' => 'Петров', 'first_name_ru' => 'Пётр',
        ]);

        foreach ([route('dashboard.students.create'), route('dashboard.students.edit', $student)] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk()
                ->assertSee('name="last_name_ru"', false)
                ->assertSee('name="first_name_ru"', false)
                ->assertSee('name="patronymic_ru"', false)
                ->assertDontSee('name="name_en"', false)
                ->assertDontSee('name="name_ar"', false)
                ->assertDontSee('name="first_name"', false)
                ->assertDontSee('name="last_name"', false);
        }
    }

    public function test_profile_update_requires_surname_and_first_name_but_not_patronymic(): void
    {
        $student = Student::create([
            'class_id' => $this->class->id, 'last_name_ru' => 'Старый', 'first_name_ru' => 'Ученик',
        ]);

        $this->actingAs($this->admin)->put(route('dashboard.students.update', $student), [
            'class_id' => $this->class->id, 'last_name_ru' => '  Сидоров ', 'first_name_ru' => ' Иван  Иван ',
        ])->assertRedirect(route('dashboard.students.index'));

        $this->assertSame('Сидоров Иван Иван', $student->fresh()->full_name);
        $this->actingAs($this->admin)->put(route('dashboard.students.update', $student), [
            'class_id' => $this->class->id, 'last_name_ru' => ' ', 'first_name_ru' => ' ',
        ])->assertSessionHasErrors(['last_name_ru', 'first_name_ru']);
    }

    public function test_latest_migration_removes_name_en_safely_and_can_restore_it(): void
    {
        $migration = require database_path('migrations/2026_08_03_100000_remove_english_name_from_students_table.php');

        $this->assertFalse(Schema::hasColumn('students', 'name_en'));
        $migration->down();
        $this->assertTrue(Schema::hasColumn('students', 'name_en'));
        $migration->up();
        $this->assertFalse(Schema::hasColumn('students', 'name_en'));
        $this->assertTrue(Schema::hasColumn('students', 'name'));
    }
}
