<?php

namespace Tests\Feature;

use App\Filament\Resources\AcademicYears\Pages\CreateAcademicYear;
use App\Filament\Resources\AcademicYears\Pages\EditAcademicYear;
use App\Filament\Resources\AcademicYears\Pages\ListAcademicYears;
use App\Models\AcademicYear;
use App\Models\User;
use Database\Seeders\AcademicYearSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AcademicYearTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression test: AcademicYear had no $fillable at all, so Eloquent's
     * default $guarded = ['*'] silently dropped every attribute passed to
     * firstOrCreate() — name (a NOT NULL column) was never set, and the
     * seeder failed with a database error the moment it ran.
     */
    public function test_seeder_runs_without_error_and_creates_the_year(): void
    {
        (new AcademicYearSeeder())->run();

        $this->assertDatabaseHas('academic_years', [
            'name' => '2025 / 2026',
            'is_active' => true,
        ]);
    }

    public function test_seeder_is_idempotent(): void
    {
        (new AcademicYearSeeder())->run();
        (new AcademicYearSeeder())->run();

        $this->assertDatabaseCount('academic_years', 1);
    }

    public function test_model_mass_assignment_persists_all_fields(): void
    {
        $year = AcademicYear::create([
            'name' => '2026 / 2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-05-31',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('academic_years', [
            'id' => $year->id,
            'name' => '2026 / 2027',
            'is_active' => true,
        ]);

        $this->assertTrue($year->fresh()->is_active);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $year->fresh()->start_date);
    }

    public function test_enrollments_relationship_resolves(): void
    {
        $year = AcademicYear::create([
            'name' => '2026 / 2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-05-31',
            'is_active' => true,
        ]);

        $this->assertCount(0, $year->enrollments);
    }

    /**
     * Regression test: the Filament form/table referenced a nonexistent
     * is_current column (the real column is is_active) — creating or
     * editing an Academic Year through the admin panel threw a SQL error
     * ("no such column: is_current").
     */
    public function test_filament_can_create_an_academic_year(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateAcademicYear::class)
            ->fillForm([
                'name' => '2027 / 2028',
                'start_date' => '2027-09-01',
                'end_date' => '2028-05-31',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('academic_years', [
            'name' => '2027 / 2028',
            'is_active' => true,
        ]);
    }

    public function test_filament_can_edit_an_academic_year(): void
    {
        $user = User::factory()->create();

        $year = AcademicYear::create([
            'name' => '2026 / 2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-05-31',
            'is_active' => false,
        ]);

        Livewire::actingAs($user)
            ->test(EditAcademicYear::class, ['record' => $year->id])
            ->fillForm(['is_active' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($year->fresh()->is_active);
    }

    public function test_filament_list_page_renders_and_shows_active_state(): void
    {
        $user = User::factory()->create();

        AcademicYear::create([
            'name' => '2026 / 2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-05-31',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test(ListAcademicYears::class)
            ->assertSuccessful()
            ->assertSee('2026 / 2027');
    }
}
