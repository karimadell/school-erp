<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Grade;
use App\Models\ClassRoom;

class ClassSeeder extends Seeder
{
    public function run(): void
    {
        $grades = Grade::all();

        foreach ($grades as $grade) {
            foreach (['A', 'B', 'C'] as $index => $code) {
                ClassRoom::create([
                    'grade_id' => $grade->id,
                    'code'     => $code,
                    'name_ar'  => 'فصل ' . $code,
                    'name_ru'  => 'Класс ' . $code,
                    'capacity' => 25,
                    'is_active'=> true,
                ]);
            }
        }
    }
}