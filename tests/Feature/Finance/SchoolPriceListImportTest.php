<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\MealPlan;
use App\Services\Finance\InvoiceCalculationService;
use App\Services\Finance\SchoolPriceListImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolPriceListImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_catalog_and_all_variants_are_imported(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => true]);

        $result = app(SchoolPriceListImportService::class)->import();

        $this->assertSame(6, $result['services_created']);
        // Uniform corrective pass: 40 new individual-exact-size tariff rows
        // (3 legacy tiers x 4 items, decomposed into 3+3+4=10 exact sizes
        // each) are now imported ALONGSIDE the original 34 — never
        // replacing them. 34 + 40 = 74.
        $this->assertSame(74, $result['tariffs_created']);
        $this->assertSame([], $result['conflicts']);
        $this->assertEqualsCanonicalizing(['Организационный взнос', 'Обучение', 'Питание', 'Трансфер', 'Школьная форма', 'Экстернат'], Fee::pluck('name_ru')->all());
        $this->assertSame(74, FeePrice::count());
        $this->assertSame(74, FeePrice::where('academic_year_id', $year->id)->where('currency', 'EGP')->where('change_reason', SchoolPriceListImportService::REASON)->count());
        $this->assertSame(10, Fee::where('name_ru', 'Обучение')->firstOrFail()->prices()->count());
        $this->assertSame(6, Fee::where('name_ru', 'Трансфер')->firstOrFail()->prices()->count());
        $this->assertSame(3, Fee::where('name_ru', 'Питание')->firstOrFail()->prices()->count());
        $this->assertSame(2, Fee::where('name_ru', 'Экстернат')->firstOrFail()->prices()->count());
        // 12 legacy grouped-tier rows (unchanged, never removed) + 40 new
        // individual-exact-size rows = 52.
        $this->assertSame(52, Fee::where('name_ru', 'Школьная форма')->firstOrFail()->prices()->count());
        $this->assertDatabaseHas('fee_prices', ['amount' => '67500.00', 'grade_group' => '9–11 классы', 'payment_period' => 'yearly']);
        $this->assertDatabaseHas('fee_prices', ['amount' => '1500.00', 'option_type' => 'zone', 'option_value' => 'Каусер, Мубарак 2, Интерконтиненталь', 'payment_period' => 'monthly']);
        // Legacy grouped-tier row — still imported exactly as before, never removed/reinterpreted.
        $this->assertDatabaseHas('fee_prices', ['amount' => '1500.00', 'item' => 'Толстовка', 'size' => 'от S']);
        // New individual-exact-size rows, decomposed from that same 'от S' tier's price.
        $this->assertDatabaseHas('fee_prices', ['amount' => '1500.00', 'item' => 'Толстовка', 'size' => 'M']);
        $this->assertDatabaseHas('fee_prices', ['amount' => '900.00', 'item' => 'Толстовка', 'size' => '6']);
        $this->assertDatabaseHas('fee_prices', ['amount' => '1200.00', 'item' => 'Толстовка', 'size' => '12']);
        $this->assertDatabaseMissing('fee_prices', ['currency' => 'RUB']);
        $this->assertDatabaseMissing('fee_prices', ['option_type' => 'Район']);

        // Food is priced per real MealPlan record, the same convention
        // Quick Registration resolves prices by — not the old free-text
        // "item" catalog, which no live consumer ever queried by.
        $breakfast = MealPlan::where('name_ru', 'Завтрак')->firstOrFail();
        $this->assertSame(MealPlan::TYPE_BREAKFAST, $breakfast->meal_type);
        $this->assertDatabaseHas('fee_prices', [
            'amount' => '70.00', 'option_type' => 'meal_plan', 'option_value' => (string) $breakfast->id, 'payment_period' => 'daily',
        ]);
        $this->assertSame(3, MealPlan::count());

        // The importer's food rows must actually be resolvable through the
        // canonical resolver by the same selection Quick Registration sends.
        $food = Fee::where('name_ru', 'Питание')->firstOrFail();
        $calculation = app(InvoiceCalculationService::class)->calculate(
            items: [[
                'fee_id' => $food->id, 'quantity' => 1,
                'option_type' => 'meal_plan', 'option_value' => (string) $breakfast->id,
            ]],
            pricingDate: $year->start_date->toDateString(),
            academicYearId: $year->id,
        );
        $this->assertSame('70.00', $calculation['line_items'][0]['unit_price']);
    }

    public function test_missing_academic_year_fails_in_russian(): void
    {
        $this->expectExceptionMessage('Учебный год 2025/2026 не найден');
        app(SchoolPriceListImportService::class)->import();
    }
}
