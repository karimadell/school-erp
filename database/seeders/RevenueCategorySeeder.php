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
        ] as $category) {
            RevenueCategory::firstOrCreate(
                ['code' => $category['code']],
                ['name_ru' => $category['name_ru'], 'is_active' => true]
            );
        }
    }
}
