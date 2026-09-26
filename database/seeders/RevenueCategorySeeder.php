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
            // reclassification of it. school_food was originally seeded
            // INACTIVE — reserved for the not-yet-built Staff Food
            // feature (same "reserve the identity, don't expose it yet"
            // pattern already used by EnrollmentModeSeeder for
            // family/external/no_enrollment). Stolovaya Phase 2 (Employee
            // cash purchases) now exists, so that reservation condition is
            // satisfied and it seeds ACTIVE from here on. An
            // already-provisioned database (this seeder already ran once
            // with is_active=false, and firstOrCreate() never updates an
            // existing row) is activated separately by
            // 2026_09_26_120100_activate_school_food_revenue_category.
            // Buffet's own shortcut has been live since before this.
            ['code' => RevenueCategory::CODE_SCHOOL_FOOD, 'name_ru' => 'Школьное питание', 'is_active' => true],
            ['code' => RevenueCategory::CODE_BUFFET, 'name_ru' => 'Буфет', 'is_active' => true],
        ] as $category) {
            RevenueCategory::firstOrCreate(
                ['code' => $category['code']],
                ['name_ru' => $category['name_ru'], 'is_active' => $category['is_active'] ?? true]
            );
        }
    }
}
