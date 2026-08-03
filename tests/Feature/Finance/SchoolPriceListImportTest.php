<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
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
        $this->assertSame(37, $result['tariffs_created']);
        $this->assertSame([], $result['conflicts']);
        $this->assertEqualsCanonicalizing(['Организационный взнос', 'Обучение', 'Питание', 'Трансфер', 'Школьная форма', 'Экстернат'], Fee::pluck('name_ru')->all());
        $this->assertSame(37, FeePrice::count());
        $this->assertSame(37, FeePrice::where('academic_year_id', $year->id)->where('currency', 'EGP')->where('change_reason', SchoolPriceListImportService::REASON)->count());
        $this->assertSame(10, Fee::where('name_ru', 'Обучение')->firstOrFail()->prices()->count());
        $this->assertSame(6, Fee::where('name_ru', 'Трансфер')->firstOrFail()->prices()->count());
        $this->assertSame(6, Fee::where('name_ru', 'Питание')->firstOrFail()->prices()->count());
        $this->assertSame(2, Fee::where('name_ru', 'Экстернат')->firstOrFail()->prices()->count());
        $this->assertSame(12, Fee::where('name_ru', 'Школьная форма')->firstOrFail()->prices()->count());
        $this->assertDatabaseHas('fee_prices', ['amount' => '67500.00', 'grade_group' => '9–11 классы', 'payment_period' => 'yearly']);
        $this->assertDatabaseHas('fee_prices', ['amount' => '1500.00', 'option_type' => 'Район', 'option_value' => 'Каусер, Мубарак 2, Интерконтиненталь', 'payment_period' => 'monthly']);
        $this->assertDatabaseHas('fee_prices', ['amount' => '20.00', 'item' => 'Суп', 'payment_period' => 'daily']);
        $this->assertDatabaseHas('fee_prices', ['amount' => '1500.00', 'item' => 'Толстовка', 'size' => 'от S']);
        $this->assertDatabaseMissing('fee_prices', ['currency' => 'RUB']);
    }

    public function test_missing_academic_year_fails_in_russian(): void
    {
        $this->expectExceptionMessage('Учебный год 2025/2026 не найден');
        app(SchoolPriceListImportService::class)->import();
    }
}
