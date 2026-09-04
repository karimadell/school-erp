<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Services\Finance\SchoolPriceListImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolPriceListImportConflictTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_overlapping_tariff_is_reported_and_never_overwritten(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => true]);
        $fee = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'type' => 'service', 'amount' => '0.00', 'is_active' => true]);
        $manual = FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '41000.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'grade_group' => '1–4 классы',
            'payment_period' => 'yearly', 'is_active' => true, 'change_reason' => 'Ручная цена директора',
        ]);

        $result = app(SchoolPriceListImportService::class)->import();

        $this->assertCount(1, $result['conflicts']);
        $this->assertStringContainsString('пересекается', $result['conflicts'][0]);
        $this->assertSame('41000.00', $manual->fresh()->amount);
        $this->assertSame('Ручная цена директора', $manual->fresh()->change_reason);
        $this->assertDatabaseMissing('fee_prices', ['fee_id' => $fee->id, 'grade_group' => '1–4 классы', 'payment_period' => 'yearly', 'amount' => '40500.00']);
        // Uniform corrective pass added 40 new individual-exact-size
        // tariffs alongside the original 34; one Tuition row is skipped
        // here due to the manual conflict, same as before: 74 - 1 = 73.
        $this->assertSame(73, $result['tariffs_created']);
    }
}
