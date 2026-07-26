<?php

namespace Tests\Unit;

use App\Models\Grade;
use App\Models\Period;
use App\Models\Stage;
use Database\Seeders\GradeSeeder;
use Database\Seeders\PeriodSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * StageSeeder used Stage::firstOrCreate(['title' => ...]) but the
     * stages table's column is 'name', which threw a SQL error and halted
     * DatabaseSeeder before GradeSeeder (which runs right after it) ever
     * executed. GradeSeeder separately never supplied grades.stage_id
     * (a required NOT NULL column), which would have failed on its own too.
     */
    public function test_stage_and_grade_seeders_run_in_sequence_without_error(): void
    {
        (new StageSeeder())->run();
        (new GradeSeeder())->run();

        $this->assertSame(4, Stage::count());
        $this->assertSame(6, Grade::count());

        $primary = Stage::where('name', 'Primary')->firstOrFail();
        $this->assertSame(6, Grade::where('stage_id', $primary->id)->count());
    }

    /**
     * Period had no $fillable (Eloquent's default $guarded = ['*']
     * applies), so any mass-assigned Period::create() — including
     * PeriodSeeder's — threw a MassAssignmentException before this fix.
     */
    public function test_period_is_mass_assignable(): void
    {
        $period = Period::create([
            'number' => 1,
            'start_time' => '08:00',
            'end_time' => '08:45',
        ]);

        $this->assertSame(1, $period->fresh()->number);
    }

    public function test_period_seeder_runs_without_error(): void
    {
        (new PeriodSeeder())->run();

        $this->assertSame(7, Period::count());
        $this->assertTrue(Period::where('number', 1)->exists());
    }
}
