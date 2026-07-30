<?php

namespace Database\Seeders;

use App\Models\EnrollmentMode;
use Illuminate\Database\Seeder;

class EnrollmentModeSeeder extends Seeder
{
    public function run(): void
    {
        EnrollmentMode::firstOrCreate(
            ['code' => EnrollmentMode::REGULAR],
            ['name_ru' => 'Очное обучение', 'name_ar' => 'تعليم نظامي', 'name_en' => 'Regular']
        );

        EnrollmentMode::firstOrCreate(
            ['code' => EnrollmentMode::DISTANCE_LEARNING],
            ['name_ru' => 'Дистанционное обучение', 'name_ar' => 'تعليم عن بعد', 'name_en' => 'Distance learning']
        );
    }
}
