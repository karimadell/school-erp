<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Stage;

class StageSeeder extends Seeder
{
    public function run(): void
    {

        $stages = [
            'Kindergarten',
            'Primary',
            'Preparatory',
            'Secondary'
        ];

        foreach ($stages as $stage) {

            // The stages table's column is 'name', not 'title' — this
            // previously threw "column not found: title" and halted the
            // whole db:seed pipeline before StageSeeder even finished,
            // since DatabaseSeeder runs this before GradeSeeder.
            Stage::firstOrCreate([
                'name' => $stage
            ]);
        }
    }
}