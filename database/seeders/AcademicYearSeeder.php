<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AcademicYear;

class AcademicYearSeeder extends Seeder
{
    public function run(): void
    {

        AcademicYear::firstOrCreate(
            ['name' => '2025 / 2026'],
            ['is_current' => true]
        );

    }
}