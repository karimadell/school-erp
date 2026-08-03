<?php

namespace Database\Seeders;

use App\Models\EnrollmentMode;
use Illuminate\Database\Seeder;

class EnrollmentModeSeeder extends Seeder
{
    public function run(): void
    {
        if (EnrollmentMode::query()->exists()) {
            return;
        }

        EnrollmentMode::create([
            'code' => EnrollmentMode::FULL_TIME,
            'name_ru' => 'Очная форма обучения',
            'short_name_ru' => 'Очная',
            'display_order' => 0,
            'is_active' => true,
        ]);
    }
}
