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

            Stage::firstOrCreate([
                'title' => $stage
            ]);
        }
    }
}