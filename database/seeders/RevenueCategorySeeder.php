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
            // reclassification of it. school_food is seeded INACTIVE —
            // reserved for the not-yet-built Staff Food feature, and must
            // not be operator-selectable through the generic RevenueEntry
            // workflow until that feature exists (same "reserve the
            // identity, don't expose it yet" pattern already used by
            // EnrollmentModeSeeder for family/external/no_enrollment).
            // Buffet's own shortcut is live now, so it seeds active.
            ['code' => RevenueCategory::CODE_SCHOOL_FOOD, 'name_ru' => 'Школьное питание', 'is_active' => false],
            ['code' => RevenueCategory::CODE_BUFFET, 'name_ru' => 'Буфет', 'is_active' => true],
        ] as $category) {
            RevenueCategory::firstOrCreate(
                ['code' => $category['code']],
                ['name_ru' => $category['name_ru'], 'is_active' => $category['is_active'] ?? true]
            );
        }
    }
}
