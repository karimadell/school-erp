<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([

            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,

            StageSeeder::class,
            GradeSeeder::class,
            AcademicYearSeeder::class,
            QuarterSeeder::class,
            PeriodSeeder::class,
            DaySeeder::class,
            EnrollmentModeSeeder::class,
            DocumentTypeSeeder::class,

        ]);
    }
}