<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Services\Finance\SchoolPriceListImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolPriceListImportIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_run_reuses_services_and_skips_every_tariff(): void
    {
        AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => true]);
        $importer = app(SchoolPriceListImportService::class);
        $importer->import();
        $second = $importer->import();

        $this->assertSame(0, $second['services_created']);
        $this->assertSame(6, $second['services_reused']);
        $this->assertSame(0, $second['tariffs_created']);
        $this->assertSame(37, $second['tariffs_skipped']);
        $this->assertSame([], $second['conflicts']);
        $this->assertSame(6, Fee::count());
        $this->assertSame(37, FeePrice::count());
    }

    public function test_dry_run_and_command_dry_run_change_nothing(): void
    {
        AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => true]);
        $result = app(SchoolPriceListImportService::class)->import(true);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, Fee::count());
        $this->artisan('finance:import-price-list-2025-2026', ['--dry-run' => true])
            ->expectsOutputToContain('Валюта: EGP')
            ->expectsOutputToContain('База данных не изменена')
            ->assertSuccessful();
        $this->assertSame(0, Fee::count());
    }
}
