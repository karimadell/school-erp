<?php

namespace Tests\Unit;

use App\Models\Grade;
use App\Models\Stage;
use Database\Seeders\GradeSeeder;
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
}
