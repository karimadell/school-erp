<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class QuickRegistrationUxTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');
    }

    /** @return array{AcademicYear, Stage, Grade, SchoolClass, EnrollmentMode} */
    protected function structure(): array
    {
        $year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $stage->id, 'level' => 1]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'А', 'name_ru' => 'А', 'name_ar' => 'A', 'is_active' => true]);
        $mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очная форма', 'is_active' => true]);

        return [$year, $stage, $grade, $class, $mode];
    }

    protected function fee(string $name = 'Регистрационный взнос', string $category = Fee::CATEGORY_REGISTRATION, bool $active = true): Fee
    {
        return Fee::create(['name_ru' => $name, 'category' => $category, 'amount' => '1000.00', 'is_active' => $active]);
    }

    protected function payload(array $structure, Fee $fee, array $overrides = []): array
    {
        [$year, $stage, $grade, $class, $mode] = $structure;

        return array_replace_recursive([
            'student_last_name_ru' => 'Иванов', 'student_first_name_ru' => 'Иван',
            'phone' => '+20 100 000 0000', 'registration_date' => '2026-08-02',
            'academic_year_id' => $year->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id,
            'class_id' => $class->id, 'enrollment_mode_id' => $mode->id,
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ], $overrides);
    }
}
