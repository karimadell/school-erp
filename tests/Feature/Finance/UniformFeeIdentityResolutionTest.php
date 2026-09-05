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
 * UAT-blocking defect: importUniformOnly() (and the command's
 * currentCounts()) resolved the operational Uniform Fee by an exact,
 * case-sensitive name_ru match against the catalog's hardcoded
 * 'Школьная форма'. Real UAT's operational Uniform Fee is stored as
 * 'ШКОЛЬНАЯ ФОРМА' (all caps) — under this project's Postgres collation
 * that is a different string, so the lookup found nothing and would have
 * created a SECOND, duplicate Uniform Fee. Fixed by resolving the
 * Uniform-only path by category + is_test_data=false first, falling back
 * to the existing name-based create-or-reuse logic only when zero
 * eligible Fees exist, and failing closed when more than one does. The
 * shared resolveOrCreateFee() used by import() for the other 5 catalog()
 * categories is completely untouched.
 */
class UniformFeeIdentityResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const EXACT_SIZES = ['6', '8', '10', '12', '14', '16', 'S', 'M', 'L', 'XL'];

    private const LEGACY_SIZES = ['6–10', '12–16', 'от S'];

    private function makeYear(string $name = '2026/2027'): AcademicYear
    {
        return AcademicYear::create(['name' => $name, 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
    }

    /** Mirrors the real UAT operational Uniform Fee exactly: differently-cased name_ru, is_active=true, is_test_data=false. */
    private function uatLikeOperationalFee(): Fee
    {
        return Fee::create(['name_ru' => 'ШКОЛЬНАЯ ФОРМА', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '0.00', 'is_active' => true, 'is_test_data' => false]);
    }

    /** The 12 legacy grouped-size rows exactly as they exist on the real UAT Fee, for one item across all 3 tiers. */
    private function seedLegacyRowsFor(Fee $fee, AcademicYear $year): void
    {
        $tierPrices = [
            '6–10' => ['Комплект' => '2000.00', 'Майка' => '400.00', 'Поло' => '600.00', 'Толстовка' => '900.00'],
            '12–16' => ['Комплект' => '2500.00', 'Майка' => '500.00', 'Поло' => '700.00', 'Толстовка' => '1200.00'],
            'от S' => ['Комплект' => '3000.00', 'Майка' => '500.00', 'Поло' => '800.00', 'Толстовка' => '1500.00'],
        ];
        foreach ($tierPrices as $size => $items) {
            foreach ($items as $item => $amount) {
                FeePrice::create([
                    'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => $amount, 'currency' => 'EGP',
                    'start_date' => $year->start_date, 'end_date' => $year->end_date, 'payment_period' => Fee::PERIOD_ONCE,
                    'item' => $item, 'size' => $size, 'is_active' => true,
                ]);
            }
        }
    }

    // ------------------------------------------------------------------
    // A. UAT-like differently-cased Fee is reused, not duplicated.
    // ------------------------------------------------------------------
    public function test_differently_cased_uniform_fee_is_reused(): void
    {
        $year = $this->makeYear();
        $existing = $this->uatLikeOperationalFee();

        $result = app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $this->assertSame(1, $result['services_reused']);
        $this->assertSame(0, $result['services_created']);
        $this->assertSame('ШКОЛЬНАЯ ФОРМА', $existing->fresh()->name_ru, 'name_ru must never be rewritten');
    }

    // ------------------------------------------------------------------
    // B. No duplicate Uniform Fee is created.
    // ------------------------------------------------------------------
    public function test_no_duplicate_uniform_fee_is_created(): void
    {
        $year = $this->makeYear();
        $this->uatLikeOperationalFee();

        app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $this->assertSame(1, Fee::where('category', Fee::CATEGORY_UNIFORM)->count());
    }

    // ------------------------------------------------------------------
    // C. All 40 exact-size rows attach to the PRE-EXISTING Fee id.
    // ------------------------------------------------------------------
    public function test_exact_size_rows_attach_to_preexisting_fee_id(): void
    {
        $year = $this->makeYear();
        $existing = $this->uatLikeOperationalFee();

        app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $this->assertSame(40, FeePrice::where('fee_id', $existing->id)->where('academic_year_id', $year->id)
            ->whereIn('size', self::EXACT_SIZES)->where('is_active', true)->count());
    }

    // ------------------------------------------------------------------
    // D. Existing 12 legacy rows on that same Fee deactivate only after
    // complete replacement.
    // ------------------------------------------------------------------
    public function test_existing_legacy_rows_on_preexisting_fee_deactivate_after_complete_replacement(): void
    {
        $year = $this->makeYear();
        $existing = $this->uatLikeOperationalFee();
        $this->seedLegacyRowsFor($existing, $year);

        app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $legacyRows = FeePrice::where('fee_id', $existing->id)->where('academic_year_id', $year->id)->whereIn('size', self::LEGACY_SIZES)->get();
        $this->assertCount(12, $legacyRows);
        $this->assertSame(0, $legacyRows->where('is_active', true)->count());
    }

    // ------------------------------------------------------------------
    // E. Two eligible operational Uniform Fees -> fail closed, zero writes.
    // ------------------------------------------------------------------
    public function test_two_eligible_uniform_fees_fail_closed_with_no_writes(): void
    {
        $year = $this->makeYear();
        $first = Fee::create(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '0.00', 'is_active' => true, 'is_test_data' => false]);
        $second = Fee::create(['name_ru' => 'ШКОЛЬНАЯ ФОРМА (старая)', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '0.00', 'is_active' => true, 'is_test_data' => false]);

        $this->expectException(\RuntimeException::class);
        try {
            app(SchoolPriceListImportService::class)->importUniformOnly($year->name);
        } finally {
            $this->assertSame(0, FeePrice::count(), 'no tariff may be written when the operational Fee is ambiguous');
            $this->assertSame('Школьная форма', $first->fresh()->name_ru);
            $this->assertSame('ШКОЛЬНАЯ ФОРМА (старая)', $second->fresh()->name_ru);
            $this->assertSame(2, Fee::where('category', Fee::CATEGORY_UNIFORM)->count(), 'neither pre-existing Fee may be touched, merged, or removed');
        }
    }

    // ------------------------------------------------------------------
    // F. A test-data Uniform Fee does not hijack resolution when a real
    // non-test Uniform Fee also exists.
    // ------------------------------------------------------------------
    public function test_test_data_uniform_fee_is_ignored_when_a_real_one_exists(): void
    {
        $year = $this->makeYear();
        $real = $this->uatLikeOperationalFee();
        $testFixture = Fee::create(['name_ru' => 'UAT_UNIFORM_TEST', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '0.00', 'is_active' => true, 'is_test_data' => true]);

        $result = app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $this->assertSame(1, $result['services_reused']);
        $this->assertSame($real->id, FeePrice::whereIn('size', self::EXACT_SIZES)->first()?->fee_id);
        $this->assertSame(0, FeePrice::where('fee_id', $testFixture->id)->count(), 'the test-data Fee must never receive tariffs');
    }

    // ------------------------------------------------------------------
    // G. ONLY a test-data Uniform Fee exists -> zero eligible candidates,
    // falls through to create a fresh, real (non-test) operational Fee.
    // The test fixture itself is never reused.
    // ------------------------------------------------------------------
    public function test_only_test_data_fee_exists_creates_a_fresh_real_fee_instead_of_reusing_it(): void
    {
        $year = $this->makeYear();
        $testFixture = Fee::create(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '0.00', 'is_active' => true, 'is_test_data' => true]);

        $result = app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $this->assertSame(1, $result['services_created'], 'zero eligible non-test candidates must fall through to create-new, not reuse the test fixture');
        $this->assertSame(0, $result['services_reused']);
        $this->assertSame(2, Fee::where('category', Fee::CATEGORY_UNIFORM)->count(), 'the test fixture remains, plus one newly-created real Fee');
        $this->assertSame(0, FeePrice::where('fee_id', $testFixture->id)->count(), 'the test fixture must never receive tariffs');
        $newFee = Fee::where('category', Fee::CATEGORY_UNIFORM)->where('is_test_data', false)->sole();
        $this->assertSame(40, FeePrice::where('fee_id', $newFee->id)->whereIn('size', self::EXACT_SIZES)->where('is_active', true)->count());
    }

    // ------------------------------------------------------------------
    // H. Command output correctly reports against a differently-cased
    // existing operational Fee (not "0 из 40").
    // ------------------------------------------------------------------
    public function test_command_reports_correct_counts_for_differently_cased_fee(): void
    {
        $year = $this->makeYear();
        $this->uatLikeOperationalFee();

        Artisan::call('finance:import-uniform-exact-sizes', ['--year' => $year->name, '--force' => true]);

        $this->assertStringContainsString('40 из 40', Artisan::output());
    }

    // ------------------------------------------------------------------
    // I. Full historical importer remains unchanged.
    // ------------------------------------------------------------------
    public function test_full_importer_still_creates_74_feeprice_rows_unchanged(): void
    {
        AcademicYear::create(['name' => SchoolPriceListImportService::YEAR, 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => true]);

        $result = app(SchoolPriceListImportService::class)->import();

        $this->assertSame(6, $result['services_created']);
        $this->assertSame(74, $result['tariffs_created']);
        $this->assertSame([], $result['conflicts']);
    }

    // ------------------------------------------------------------------
    // J. Category isolation still holds through the new resolution path.
    // ------------------------------------------------------------------
    public function test_category_isolation_holds_through_new_resolution_path(): void
    {
        $year = $this->makeYear();
        $this->uatLikeOperationalFee();

        app(SchoolPriceListImportService::class)->importUniformOnly($year->name);

        $otherCategories = [Fee::CATEGORY_REGISTRATION, Fee::CATEGORY_TUITION, Fee::CATEGORY_TRANSPORT, Fee::CATEGORY_FOOD, Fee::CATEGORY_TUITION_EXTERNAL];
        $this->assertSame(0, Fee::whereIn('category', $otherCategories)->count());
    }

    // ------------------------------------------------------------------
    // K. Dry-run still writes nothing, even against a differently-cased
    // pre-existing Fee.
    // ------------------------------------------------------------------
    public function test_dry_run_writes_nothing_against_preexisting_fee(): void
    {
        $year = $this->makeYear();
        $existing = $this->uatLikeOperationalFee();

        $result = app(SchoolPriceListImportService::class)->importUniformOnly($year->name, dryRun: true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, FeePrice::count());
        $this->assertSame('ШКОЛЬНАЯ ФОРМА', $existing->fresh()->name_ru);
    }
}
