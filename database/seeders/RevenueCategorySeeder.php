<?php

namespace Database\Seeders;

use App\Models\RevenueCategory;
use Illuminate\Database\Seeder;

class RevenueCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => RevenueCategory::CODE_CAFETERIA, 'name_ru' => 'Кафетерий'],
            ['code' => RevenueCategory::CODE_DONATION, 'name_ru' => 'Пожертвования'],
            ['code' => RevenueCategory::CODE_FINE, 'name_ru' => 'Штрафы'],
            ['code' => RevenueCategory::CODE_OTHER, 'name_ru' => 'Прочие доходы'],
            // Owner-approved Finance category separation (pre-go-live) —
            // legacy CODE_CAFETERIA above is left completely untouched;
            // these are two new, distinct categories, never a rename or
            // reclassification of it.
            ['code' => RevenueCategory::CODE_SCHOOL_FOOD, 'name_ru' => 'Школьное питание'],
            ['code' => RevenueCategory::CODE_BUFFET, 'name_ru' => 'Буфет'],
        ] as $category) {
            RevenueCategory::firstOrCreate(
                ['code' => $category['code']],
                ['name_ru' => $category['name_ru'], 'is_active' => true]
            );
        }
    }
}
