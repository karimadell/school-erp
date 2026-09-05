<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Services\Finance\SchoolPriceListImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirmed live on real UAT: Fee #7's 12 pre-existing legacy grouped-size
 * Uniform tariffs ('6–10', '12–16', 'от S') have payment_period=NULL,
 * while the catalog's legacy definitions (uniformVariants()) carry
 * payment_period=Fee::PERIOD_ONCE ('once'). Every other canonical
 * dimension already matches exactly. processTariffs()'s exact-value
 * payment_period filter therefore never recognised these 12 rows as
 * already-existing, so a real dry-run reported tariffs_created=52 (12
 * unwanted duplicate legacy rows + 40 genuinely-new exact rows) instead
 * of the desired 40.
 *
 * Fixed via a NEW, Uniform-only-import-scoped method
 * (processLegacyUniformTariffsWithNullPaymentPeriodCompatibility()) that
 * ONLY the 3 legacy-size definitions are routed through from
 * importUniformOnly() — the 40 exact-size definitions, and everything
 * import() itself does for all 6 catalog() categories, continue through
 * the completely unmodified, shared processTariffs(). The relaxation is
 * a single, narrow exception: the payment_period dimension filter
 * accepts "NULL OR the catalog's value" instead of requiring an exact
 * match; every other dimension, the amount, and the date range must
 * still match exactly for a row to be treated as existing coverage.
 */
class UniformLegacyTariffCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private const EXACT_SIZES = ['6', '8', '10', '12', '14', '16', 'S', 'M', 'L', 'XL'];

    private const LEGACY_SIZES = ['6–10', '12–16', 'от S'];

    private const TIER_PRICES = [
        '6–10' => ['Комплект' => '2000.00', 'Майка' => '400.00', 'Поло' => '600.00', 'Толстовка' => '900.00'],
        '12–16' => ['Комплект' => '2500.00', 'Майка' => '500.00', 'Поло' => '700.00', 'Толстовка' => '1200.00'],
        'от S' => ['Комплект' => '3000.00', 'Майка' => '500.00', 'Поло' => '800.00', 'Толстовка' => '1500.00'],
    ];

    private function makeYear(string $name = '2026/2027'): AcademicYear
    {
        return AcademicYear::create(['name' => $name, 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
    }

    /** Mirrors the real UAT operational Uniform Fee: differently-cased name_ru, is_test_data=false. */
    private function uatLikeOperationalFee(): Fee
    {
        return Fee::create(['name_ru' => 'ШКОЛЬНАЯ ФОРМА', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '0.00', 'is_active' => true, 'is_test_data' => false]);
    }

    /**
     * The 12 legacy grouped-size rows EXACTLY as confirmed live on real
     * UAT: payment_period=NULL (never 'once'), every other dimension
     * matching the catalog precisely. Returns the created rows keyed by
     * "item|size" so tests can assert on the specific pre-existing ids.
     *
     * @return array<string, FeePrice>
     */
    private function seedRealUatShapedLegacyRows(Fee $fee, AcademicYear $year): array
    {
        $rows = [];
        foreach (self::TIER_PRICES as $size => $items) {
            foreach ($items as $item => $amount) {
                $rows["{$item}|{$size}"] = FeePrice::create([
                    'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => $amount, 'currency' => 'EGP',
                    'start_date' => $year->start_date, 'end_date' => $year->end_date, 'payment_period' => null,
                    'item' => $item, 'size' => $size, 'is_active' => true,
                ]);
            }
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // A & B. Pre-existing NULL-payment_period legacy rows are recognised
    // as existing/skipped; 40 created, 12 skipped, zero conflicts.
    // ------------------------------------------------------------------
    public function test_null_payment_period_legacy_rows_are_recognised_as_existing(): void
    {
        $year = $this->makeYear();
        $fee = $this->uatLikeOperationalFee();
        $this->seedRealUatShapedLegacyRows($fee, $year);

        $result = app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $this->assertSame(0, $result['services_created']);
        $this->assertSame(1, $result['services_reused']);
        $this->assertSame(40, $result['tariffs_created']);
        $this->assertSame(12, $result['tariffs_skipped']);
        $this->assertSame([], $result['conflicts']);
    }

    // ------------------------------------------------------------------
    // C & D. No second set of 12 legacy rows inserted; original row ids
    // remain the only legacy rows.
    // ------------------------------------------------------------------
    public function test_no_duplicate_legacy_rows_are_inserted_and_original_ids_survive(): void
    {
        $year = $this->makeYear();
        $fee = $this->uatLikeOperationalFee();
        $originalRows = $this->seedRealUatShapedLegacyRows($fee, $year);

        app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $legacyRows = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->whereIn('size', self::LEGACY_SIZES)->get();
        $this->assertCount(12, $legacyRows, 'exactly the original 12 legacy rows must exist — no duplicate set');

        $survivingIds = $legacyRows->pluck('id')->sort()->values()->all();
        $originalIds = collect($originalRows)->pluck('id')->sort()->values()->all();
        $this->assertSame($originalIds, $survivingIds, 'the surviving legacy rows must be the exact original ids, not a new set');
    }

    // ------------------------------------------------------------------
    // E. The 40 exact-size rows are created with payment_period='once'.
    // ------------------------------------------------------------------
    public function test_exact_size_rows_are_created_with_canonical_payment_period(): void
    {
        $year = $this->makeYear();
        $fee = $this->uatLikeOperationalFee();
        $this->seedRealUatShapedLegacyRows($fee, $year);

        app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $exactRows = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->whereIn('size', self::EXACT_SIZES)->get();
        $this->assertCount(40, $exactRows);
        $this->assertTrue($exactRows->every(fn (FeePrice $p) => $p->payment_period === Fee::PERIOD_ONCE), 'every new exact-size row must have payment_period=once, never NULL');
    }

    // ------------------------------------------------------------------
    // F. Once the 40 exact rows are active, the ORIGINAL 12 legacy rows
    // (by id) are deactivated.
    // ------------------------------------------------------------------
    public function test_original_legacy_rows_deactivate_once_exact_matrix_is_complete(): void
    {
        $year = $this->makeYear();
        $fee = $this->uatLikeOperationalFee();
        $originalRows = $this->seedRealUatShapedLegacyRows($fee, $year);

        app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        foreach ($originalRows as $row) {
            $this->assertFalse($row->fresh()->is_active, "original legacy row #{$row->id} must be deactivated once the exact-size matrix is complete");
        }
        $this->assertSame(12, FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->whereIn('size', self::LEGACY_SIZES)->count(), 'still exactly 12 legacy rows total — none duplicated');
    }

    // ------------------------------------------------------------------
    // G. A legacy NULL row whose AMOUNT differs from the catalog's tier
    // price must NOT be treated as equivalent coverage.
    // ------------------------------------------------------------------
    public function test_amount_mismatch_on_legacy_null_row_is_not_treated_as_equivalent(): void
    {
        $year = $this->makeYear();
        $fee = $this->uatLikeOperationalFee();
        // Майка/6–10 seeded at a WRONG amount (350 instead of the catalog's 400).
        $mismatched = FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '350.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'payment_period' => null,
            'item' => 'Майка', 'size' => '6–10', 'is_active' => true,
        ]);

        $result = app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        // Not treated as existing coverage: since both rows are active and
        // date ranges overlap, this falls to the pre-existing overlap/
        // conflict path — never silently skipped as "already there".
        $this->assertNotEmpty($result['conflicts'], 'an amount mismatch must never be silently accepted as equivalent coverage');
        $this->assertSame('350.00', $mismatched->fresh()->amount, 'the mismatched row itself must never be rewritten');
    }

    // ------------------------------------------------------------------
    // H. A legacy NULL row whose DATE RANGE differs from the target
    // year's own dates must NOT be treated as equivalent coverage.
    // ------------------------------------------------------------------
    public function test_date_range_mismatch_on_legacy_null_row_is_not_treated_as_equivalent(): void
    {
        $year = $this->makeYear();
        $fee = $this->uatLikeOperationalFee();
        // Майка/6–10 seeded with a shorter, non-matching end date.
        $mismatched = FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '400.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => '2027-01-31', 'payment_period' => null,
            'item' => 'Майка', 'size' => '6–10', 'is_active' => true,
        ]);

        $result = app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $this->assertNotEmpty($result['conflicts'], 'a date-range mismatch must never be silently accepted as equivalent coverage');
        $this->assertSame('2027-01-31', $mismatched->fresh()->end_date->toDateString(), 'the mismatched row itself must never be rewritten');
    }

    // ------------------------------------------------------------------
    // I. A NULL-payment_period row at an EXACT size (not a legacy tier)
    // must NOT be accepted as equivalent to the canonical exact-size
    // definition — the relaxation must never leak into the exact path.
    // ------------------------------------------------------------------
    public function test_null_payment_period_row_at_an_exact_size_is_not_accepted_as_equivalent(): void
    {
        $year = $this->makeYear();
        $fee = $this->uatLikeOperationalFee();
        $this->seedRealUatShapedLegacyRows($fee, $year);
        // A stray NULL-payment_period row already sitting at exact size "6".
        $strayExact = FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '400.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'payment_period' => null,
            'item' => 'Майка', 'size' => '6', 'is_active' => true,
        ]);

        $result = app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        // processTariffs() (unmodified — the relaxation lives only in the
        // new legacy-only method and is never invoked for exact sizes)
        // requires an EXACT payment_period match. The stray NULL row is
        // therefore entirely invisible to its dimension WHERE filter — not
        // matched, not skipped, not counted as a conflict either (there is
        // nothing in $existing to compare against) — so a genuine new
        // 'once'-tagged row is created alongside it. This is exactly the
        // proof requirement I asks for: the NULL row is never accepted as
        // equivalent to the canonical exact-size definition.
        $this->assertSame('400.00', $strayExact->fresh()->amount);
        $this->assertNull($strayExact->fresh()->payment_period, 'the stray row itself must never be rewritten');
        $canonicalExact = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->where('item', 'Майка')->where('size', '6')->where('payment_period', Fee::PERIOD_ONCE)->first();
        $this->assertNotNull($canonicalExact, 'a genuine, separate exact-size row must still be created — the stray NULL row never substitutes for it');
        $this->assertSame(2, FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->where('item', 'Майка')->where('size', '6')->count(), 'the stray row and the canonical exact row coexist as two distinct rows');
    }

    // ------------------------------------------------------------------
    // J. Non-Uniform categories remain unaffected.
    // ------------------------------------------------------------------
    public function test_non_uniform_categories_remain_unaffected(): void
    {
        $year = $this->makeYear();
        $fee = $this->uatLikeOperationalFee();
        $this->seedRealUatShapedLegacyRows($fee, $year);

        app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $otherCategories = [
            Fee::CATEGORY_REGISTRATION, Fee::CATEGORY_TUITION, Fee::CATEGORY_TRANSPORT,
            Fee::CATEGORY_FOOD, Fee::CATEGORY_TUITION_EXTERNAL,
        ];
        $this->assertSame(0, Fee::whereIn('category', $otherCategories)->count());
        $this->assertSame(0, FeePrice::whereHas('fee', fn ($q) => $q->whereIn('category', $otherCategories))->count());
    }

    // ------------------------------------------------------------------
    // K. Full historical importer remains unchanged — 74-row regression.
    // ------------------------------------------------------------------
    public function test_full_import_still_creates_74_feeprice_rows_for_2025_2026(): void
    {
        AcademicYear::create(['name' => SchoolPriceListImportService::YEAR, 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => true]);

        $result = app(SchoolPriceListImportService::class)->import();

        $this->assertSame(6, $result['services_created']);
        $this->assertSame(74, $result['tariffs_created']);
        $this->assertSame([], $result['conflicts']);
    }

    // ------------------------------------------------------------------
    // L. Dry-run writes nothing, specifically against the NULL-legacy
    // scenario.
    // ------------------------------------------------------------------
    public function test_dry_run_writes_nothing_against_null_legacy_rows(): void
    {
        $year = $this->makeYear();
        $fee = $this->uatLikeOperationalFee();
        $originalRows = $this->seedRealUatShapedLegacyRows($fee, $year);

        $result = app(SchoolPriceListImportService::class)->importUniformOnly($year->name, dryRun: true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(40, $result['tariffs_created'], 'simulated count is still reported even though rolled back');
        $this->assertSame(0, FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->whereIn('size', self::EXACT_SIZES)->count(), 'no exact-size row may persist after rollback');
        foreach ($originalRows as $row) {
            $this->assertTrue($row->fresh()->is_active, 'the original legacy rows must remain active — dry-run must not persist deactivation');
        }
        $this->assertSame(12, FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->whereIn('size', self::LEGACY_SIZES)->count());
    }

    // ------------------------------------------------------------------
    // M. PR #16's identity-resolution scenarios still compose correctly
    // with this legacy-compatibility change (both apply within the same
    // importUniformOnly() method).
    // ------------------------------------------------------------------
    public function test_multiple_operational_fees_still_fail_closed_with_legacy_null_rows_present(): void
    {
        $year = $this->makeYear();
        $first = $this->uatLikeOperationalFee();
        $this->seedRealUatShapedLegacyRows($first, $year);
        $second = Fee::create(['name_ru' => 'ШКОЛЬНАЯ ФОРМА (доп.)', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '0.00', 'is_active' => true, 'is_test_data' => false]);

        $this->expectException(\RuntimeException::class);
        try {
            app(SchoolPriceListImportService::class)->importUniformOnly($year->name);
        } finally {
            $this->assertSame(12, FeePrice::where('fee_id', $first->id)->count(), 'no write may occur when the operational Fee is ambiguous');
            $this->assertSame(0, FeePrice::where('fee_id', $second->id)->count());
        }
    }

    public function test_test_data_fee_still_ignored_with_legacy_null_rows_present_on_the_real_fee(): void
    {
        $year = $this->makeYear();
        $real = $this->uatLikeOperationalFee();
        $this->seedRealUatShapedLegacyRows($real, $year);
        $testFixture = Fee::create(['name_ru' => 'UAT_UNIFORM_TEST', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '0.00', 'is_active' => true, 'is_test_data' => true]);

        $result = app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $this->assertSame(1, $result['services_reused']);
        $this->assertSame(40, $result['tariffs_created']);
        $this->assertSame(12, $result['tariffs_skipped']);
        $this->assertSame(0, FeePrice::where('fee_id', $testFixture->id)->count(), 'the test-data Fee must never receive tariffs');
    }
}
