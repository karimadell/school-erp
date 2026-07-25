<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Grade;
use App\Models\Stage;

class GradeSeeder extends Seeder
{
    public function run(): void
    {
        // grades.stage_id is a required (NOT NULL) foreign key, but this
        // seeder never supplied one — every run failed. Grade 1-6 maps to
        // the "Primary" stage seeded by StageSeeder (which runs first);
        // fall back to the first stage if that name isn't found so this
        // never hard-fails if stages are ever renamed or reordered.
        $stage = Stage::where('name', 'Primary')->first() ?? Stage::first();

        if (! $stage) {
            return;
        }

        $grades = [
            'Grade 1',
            'Grade 2',
            'Grade 3',
            'Grade 4',
            'Grade 5',
            'Grade 6'
        ];

        foreach ($grades as $grade) {

            Grade::firstOrCreate([
                'name' => $grade,
                'stage_id' => $stage->id,
            ]);

        }
    }
}