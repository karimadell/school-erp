<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Services\Finance\SchoolPriceListImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Year-scoped, single-category Uniform import.
 *
 * SchoolPriceListImportService::import() is hard-targeted to the fixed
 * historical year SchoolPriceListImportService::YEAR ('2025/2026') and
 * processes all 6 catalog() categories in one pass — unsuitable for
 * applying just the reviewed Uniform exact-size master data to an
 * existing, different academic year (e.g. '2026/2027') without also
 * risking creating stale Registration/Tuition/Transport/Food/Externat
 * tariffs from the 2025/2026 catalog's prices. importUniformOnly()
 * reuses the exact same catalog() Uniform definition and the exact same
 * resolveOrCreateFee()/processTariffs()/
 * deactivateLegacyUniformSizesIfReplacementComplete() logic import()
 * itself uses for that one definition — no price data or business rule
 * is redefined. The only differences: the target year is an explicit,
 * required caller-supplied name (no default, fails closed if missing),
 * and only the Uniform catalog entry is ever touched.
 */
class UniformOnlyYearImportTest extends TestCase
{
    use RefreshDatabase;

    private const EXACT_SIZES = ['6', '8', '10', '12', '14', '16', 'S', 'M', 'L', 'XL'];

    private const LEGACY_SIZES = ['6–10', '12–16', 'от S'];

    private function makeYear(string $name = '2026/2027'): AcademicYear
    {
        return AcademicYear::create(['name' => $name, 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
    }

    // ------------------------------------------------------------------
    // A. Uniform-only import touches ONLY the Uniform category.
    // ------------------------------------------------------------------
    public function test_uniform_only_import_never_creates_or_changes_other_category_data(): void
    {
        $year = $this->makeYear();

        app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $otherCategories = [
            Fee::CATEGORY_REGISTRATION, Fee::CATEGORY_TUITION, Fee::CATEGORY_TRANSPORT,
            Fee::CATEGORY_FOOD, Fee::CATEGORY_TUITION_EXTERNAL,
        ];
        $this->assertSame(0, Fee::whereIn('category', $otherCategories)->count(), 'no non-Uniform Fee row may be created');
        $this->assertSame(0, FeePrice::whereHas('fee', fn ($q) => $q->whereIn('category', $otherCategories))->count(), 'no non-Uniform FeePrice row may be created');

        // The one Fee it IS allowed to touch:
        $this->assertSame(1, Fee::where('category', Fee::CATEGORY_UNIFORM)->count());
    }

    // ------------------------------------------------------------------
    // B. Creates the expected 40 active exact (item, size) combinations.
    // ------------------------------------------------------------------
    public function test_uniform_only_import_creates_40_active_exact_combinations(): void
    {
        $year = $this->makeYear();

        $result = app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $this->assertSame([], $result['conflicts']);
        $fee = Fee::where('category', Fee::CATEGORY_UNIFORM)->sole();
        $activeExactCount = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->whereIn('size', self::EXACT_SIZES)->where('is_active', true)->count();
        $this->assertSame(40, $activeExactCount);
    }

    // ------------------------------------------------------------------
    // C. Complete replacement deactivates the legacy grouped rows.
    // ------------------------------------------------------------------
    public function test_complete_replacement_deactivates_legacy_grouped_rows(): void
    {
        $year = $this->makeYear();

        app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $fee = Fee::where('category', Fee::CATEGORY_UNIFORM)->sole();
        $legacyRows = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->whereIn('size', self::LEGACY_SIZES)->get();
        $this->assertCount(12, $legacyRows, 'all 12 legacy rows (3 tiers x 4 items) must still exist');
        $this->assertSame(0, $legacyRows->where('is_active', true)->count());
    }

    // ------------------------------------------------------------------
    // D. Incomplete active replacement matrix preserves legacy rows.
    // ------------------------------------------------------------------
    public function test_incomplete_replacement_preserves_legacy_grouped_rows(): void
    {
        $year = $this->makeYear();
        $fee = Fee::firstOrCreate(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM], ['amount' => '0.00', 'is_active' => true]);

        // An inactive pre-existing row exactly matching what the import
        // would otherwise create for Майка + size 8 — the importer's own
        // exact-match check finds it and skips creating a new active row.
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '400.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'payment_period' => Fee::PERIOD_ONCE,
            'item' => 'Майка', 'size' => '8', 'is_active' => false,
        ]);

        app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $activeExactCount = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->whereIn('size', self::EXACT_SIZES)->where('is_active', true)->count();
        $this->assertSame(39, $activeExactCount, 'Майка+8 has zero active coverage — matrix incomplete');

        $legacyActiveCount = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->whereIn('size', self::LEGACY_SIZES)->where('is_active', true)->count();
        $this->assertSame(12, $legacyActiveCount, 'an incomplete replacement set must never deactivate the legacy fallback');
    }

    // ------------------------------------------------------------------
    // E. Explicit academic year is respected — rows are scoped to it.
    // ------------------------------------------------------------------
    public function test_explicit_academic_year_is_respected(): void
    {
        $targetYear = $this->makeYear('2026/2027');
        $otherYear = $this->makeYear('2099/2100');

        app(SchoolPriceListImportService::class)->importUniformOnly($targetYear->name);

        $fee = Fee::where('category', Fee::CATEGORY_UNIFORM)->sole();
        $this->assertSame(40, FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $targetYear->id)
            ->whereIn('size', self::EXACT_SIZES)->where('is_active', true)->count());
        $this->assertSame(0, FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $otherYear->id)->count(),
            'a different, unrequested academic year must never be touched');
    }

    // ------------------------------------------------------------------
    // F. Missing academic year fails closed and creates nothing.
    // ------------------------------------------------------------------
    public function test_missing_academic_year_fails_closed_and_creates_nothing(): void
    {
        $this->expectException(\RuntimeException::class);

        try {
            app(SchoolPriceListImportService::class)->importUniformOnly('Не существующий год');
        } finally {
            $this->assertSame(0, Fee::count(), 'no Fee row may survive a failed year lookup');
            $this->assertSame(0, FeePrice::count(), 'no FeePrice row may survive a failed year lookup');
        }
    }

    // ------------------------------------------------------------------
    // G. Existing full import() 2025/2026 behaviour is unchanged.
    // Covered empirically by the unmodified SchoolPriceListImportTest /
    // SchoolPriceListImportIdempotencyTest / SchoolPriceListImportConflictTest
    // suites (zero assertion changes required there after the
    // resolveOrCreateFee()/processTariffs() extraction) — re-asserted here
    // directly against the extracted service to keep the regression guard
    // colocated with this change.
    // ------------------------------------------------------------------
    public function test_full_import_still_creates_74_feeprice_rows_for_2025_2026(): void
    {
        AcademicYear::create(['name' => SchoolPriceListImportService::YEAR, 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => true]);

        $result = app(SchoolPriceListImportService::class)->import();

        $this->assertSame(6, $result['services_created']);
        $this->assertSame(74, $result['tariffs_created']); // 34 original + 40 exact-size, unchanged from the merged corrective pass.
        $this->assertSame([], $result['conflicts']);
    }

    // ------------------------------------------------------------------
    // H. Second importUniformOnly() run for the same year is idempotent.
    // ------------------------------------------------------------------
    public function test_second_uniform_only_run_is_idempotent(): void
    {
        $year = $this->makeYear();
        $service = app(SchoolPriceListImportService::class);

        $service->importUniformOnly($year->name);
        $fee = Fee::where('category', Fee::CATEGORY_UNIFORM)->sole();
        $countAfterFirst = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->count();
        $this->assertSame(52, $countAfterFirst);

        $second = $service->importUniformOnly($year->name);

        $this->assertSame(0, $second['tariffs_created']);
        $this->assertSame(52, FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->count());
        $this->assertSame(40, FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->whereIn('size', self::EXACT_SIZES)->where('is_active', true)->count());
        $this->assertSame(0, FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->whereIn('size', self::LEGACY_SIZES)->where('is_active', true)->count());
    }

    // ------------------------------------------------------------------
    // I. Dry-run creates/changes nothing at all.
    // ------------------------------------------------------------------
    public function test_dry_run_changes_nothing(): void
    {
        $year = $this->makeYear();

        $result = app(SchoolPriceListImportService::class)->importUniformOnly($year->name, dryRun: true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, Fee::count(), 'dry-run must not create the Fee row');
        $this->assertSame(0, FeePrice::count(), 'dry-run must not create any FeePrice row');
    }

    // ------------------------------------------------------------------
    // J. A pre-existing ACTIVE manual tariff satisfies completeness
    // without being overwritten.
    // ------------------------------------------------------------------
    public function test_active_manual_override_satisfies_completeness_via_uniform_only_import(): void
    {
        $year = $this->makeYear();
        $fee = Fee::firstOrCreate(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM], ['amount' => '0.00', 'is_active' => true]);
        $manual = FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '450.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'payment_period' => Fee::PERIOD_ONCE,
            'item' => 'Майка', 'size' => '8', 'is_active' => true, 'change_reason' => 'Ручная цена директора',
        ]);

        $result = app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $this->assertNotEmpty($result['conflicts']);
        $this->assertSame('450.00', $manual->fresh()->amount, 'manual tariff must never be overwritten');
        $legacyActiveCount = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->whereIn('size', self::LEGACY_SIZES)->where('is_active', true)->count();
        $this->assertSame(0, $legacyActiveCount, 'Майка+8 remains sellable via the active manual override, so legacy deactivation is safe');
    }

    // ------------------------------------------------------------------
    // K. A pre-existing INACTIVE exact-match row does NOT satisfy
    // completeness (duplicate of test D's core assertion, stated
    // explicitly for this lettered requirement).
    // ------------------------------------------------------------------
    public function test_inactive_exact_match_row_does_not_satisfy_completeness(): void
    {
        $year = $this->makeYear();
        $fee = Fee::firstOrCreate(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM], ['amount' => '0.00', 'is_active' => true]);
        $inactive = FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '400.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'payment_period' => Fee::PERIOD_ONCE,
            'item' => 'Майка', 'size' => '8', 'is_active' => false,
        ]);

        app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $this->assertFalse($inactive->fresh()->is_active, 'the importer must not reactivate what it silently treats as already-there');
        $legacyActiveCount = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->whereIn('size', self::LEGACY_SIZES)->where('is_active', true)->count();
        $this->assertSame(12, $legacyActiveCount, 'an inactive exact-match row must not count toward completeness');
    }

    // ------------------------------------------------------------------
    // Command-level: refuses to run without --year, and end-to-end works.
    // ------------------------------------------------------------------
    public function test_command_refuses_to_run_without_year_option(): void
    {
        $this->artisan('finance:import-uniform-exact-sizes', ['--force' => true])->assertFailed();

        $this->assertSame(0, Fee::count());
    }

    public function test_command_applies_uniform_only_import_for_explicit_year(): void
    {
        $year = $this->makeYear();

        Artisan::call('finance:import-uniform-exact-sizes', ['--year' => $year->name, '--force' => true]);

        $fee = Fee::where('category', Fee::CATEGORY_UNIFORM)->sole();
        $this->assertSame(40, FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->whereIn('size', self::EXACT_SIZES)->where('is_active', true)->count());
        $this->assertStringContainsString('40', Artisan::output());
    }

    public function test_command_dry_run_changes_nothing(): void
    {
        $year = $this->makeYear();

        Artisan::call('finance:import-uniform-exact-sizes', ['--year' => $year->name, '--dry-run' => true]);

        $this->assertSame(0, Fee::count());
        $this->assertSame(0, FeePrice::count());
    }
}
