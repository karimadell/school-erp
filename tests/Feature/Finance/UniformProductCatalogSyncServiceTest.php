<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\MealPlan;
use App\Models\PaymentPlan;
use App\Services\Finance\InvoiceCalculationService;
use App\Services\Finance\UniformProductCatalogSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * UniformProductCatalogSyncService synchronizes ONLY uniform_products
 * (physical SKU catalog: name_ru + size) from one explicit AcademicYear's
 * canonical exact-size Uniform FeePrice rows. It never reads or writes
 * FeePrice for pricing purposes, never touches Transport/Food/MealPlan/
 * PaymentPlan data, and fails closed (writes nothing) unless the source
 * matrix is exactly complete and unambiguous.
 */
class UniformProductCatalogSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ITEMS = ['Комплект', 'Майка', 'Поло', 'Толстовка'];

    private const SIZES = ['6', '8', '10', '12', '14', '16', 'S', 'M', 'L', 'XL'];

    private const TIER_PRICES = [
        ['sizes' => ['6', '8', '10'], 'amounts' => ['Комплект' => '2000.00', 'Майка' => '400.00', 'Поло' => '600.00', 'Толстовка' => '900.00']],
        ['sizes' => ['12', '14', '16'], 'amounts' => ['Комплект' => '2500.00', 'Майка' => '500.00', 'Поло' => '700.00', 'Толстовка' => '1200.00']],
        ['sizes' => ['S', 'M', 'L', 'XL'], 'amounts' => ['Комплект' => '3000.00', 'Майка' => '500.00', 'Поло' => '800.00', 'Толстовка' => '1500.00']],
    ];

    private function makeYear(string $name = '2026/2027', string $start = '2026-09-01', string $end = '2027-06-30'): AcademicYear
    {
        return AcademicYear::create(['name' => $name, 'start_date' => $start, 'end_date' => $end, 'is_active' => true]);
    }

    private function makeUniformFee(bool $isTestData = false): Fee
    {
        return Fee::create([
            'name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'type' => 'service',
            'payment_period' => Fee::PERIOD_PACKAGE, 'amount' => '0.00', 'is_active' => true, 'is_test_data' => $isTestData,
        ]);
    }

    private function amountFor(string $item, string $size): string
    {
        foreach (self::TIER_PRICES as $tier) {
            if (in_array($size, $tier['sizes'], true)) {
                return $tier['amounts'][$item];
            }
        }
        throw new RuntimeException("no tier for size {$size}");
    }

    /** @param array<int, string> $skip "item|size" keys to omit */
    private function seedFullExactMatrix(Fee $fee, AcademicYear $year, array $skip = []): void
    {
        foreach (self::ITEMS as $item) {
            foreach (self::SIZES as $size) {
                if (in_array($item.'|'.$size, $skip, true)) {
                    continue;
                }
                FeePrice::create([
                    'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => $this->amountFor($item, $size),
                    'currency' => 'EGP', 'item' => $item, 'size' => $size, 'payment_period' => Fee::PERIOD_ONCE,
                    'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true,
                ]);
            }
        }
    }

    private function seedLegacyGroupedRows(Fee $fee, AcademicYear $year, bool $active = true): void
    {
        $groups = [
            '6–10' => ['Комплект' => '2000.00', 'Майка' => '400.00', 'Поло' => '600.00', 'Толстовка' => '900.00'],
            '12–16' => ['Комплект' => '2500.00', 'Майка' => '500.00', 'Поло' => '700.00', 'Толстовка' => '1200.00'],
            'от S' => ['Комплект' => '3000.00', 'Майка' => '500.00', 'Поло' => '800.00', 'Толстовка' => '1500.00'],
        ];
        foreach ($groups as $size => $amounts) {
            foreach ($amounts as $item => $amount) {
                FeePrice::create([
                    'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => $amount,
                    'currency' => 'EGP', 'item' => $item, 'size' => $size, 'payment_period' => Fee::PERIOD_ONCE,
                    'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => $active,
                ]);
            }
        }
    }

    // ------------------------------------------------------------------
    // 1. Complete canonical matrix creates exactly 40 products.
    // ------------------------------------------------------------------
    public function test_complete_canonical_matrix_creates_exactly_40_products(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);

        $result = app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        $this->assertSame(40, $result['created']);
        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['ambiguous_source_pairs']);
        $this->assertSame([], $result['duplicate_catalog_pairs']);
        $this->assertTrue($result['applied']);
        $this->assertSame(40, DB::table('uniform_products')->where('is_active', true)->count());
    }

    // ------------------------------------------------------------------
    // 2. Dry-run writes zero rows.
    // ------------------------------------------------------------------
    public function test_dry_run_writes_zero_rows(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);

        $result = app(UniformProductCatalogSyncService::class)->sync($year, apply: false);

        $this->assertSame(40, $result['created'], 'dry-run must still report what WOULD be created');
        $this->assertFalse($result['applied']);
        $this->assertSame(0, DB::table('uniform_products')->count());
    }

    // ------------------------------------------------------------------
    // 3. Second apply is idempotent.
    // ------------------------------------------------------------------
    public function test_second_apply_is_idempotent(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);
        $service = app(UniformProductCatalogSyncService::class);

        $service->sync($year, apply: true);
        $second = $service->sync($year, apply: true);

        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['reactivated']);
        $this->assertSame(40, $second['unchanged']);
        $this->assertSame(0, $second['deactivated']);
        $this->assertSame(40, DB::table('uniform_products')->where('is_active', true)->count());
    }

    // ------------------------------------------------------------------
    // 4. Legacy grouped FeePrices ignored even if active.
    // ------------------------------------------------------------------
    public function test_legacy_grouped_feeprices_ignored_even_if_active(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);
        $this->seedLegacyGroupedRows($fee, $year, active: true);

        $result = app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        $this->assertSame(40, $result['created']);
        $this->assertSame(40, DB::table('uniform_products')->count(), 'legacy grouped sizes must never produce catalog rows');
        $this->assertSame(0, DB::table('uniform_products')->whereIn('size', ['6–10', '12–16', 'от S'])->count());
    }

    // ------------------------------------------------------------------
    // 5. Inactive exact FeePrice makes matrix incomplete -> zero writes.
    // ------------------------------------------------------------------
    public function test_inactive_exact_feeprice_makes_matrix_incomplete_and_causes_zero_writes(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);
        FeePrice::where('fee_id', $fee->id)->where('item', 'Майка')->where('size', '14')->update(['is_active' => false]);

        $result = app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        $this->assertContains('Майка|14', $result['missing']);
        $this->assertFalse($result['applied']);
        $this->assertSame(0, DB::table('uniform_products')->count());
    }

    // ------------------------------------------------------------------
    // 6. Wrong academic year rows ignored.
    // ------------------------------------------------------------------
    public function test_wrong_academic_year_rows_ignored(): void
    {
        $targetYear = $this->makeYear('2026/2027', '2026-09-01', '2027-06-30');
        $otherYear = $this->makeYear('2025/2026', '2025-09-01', '2026-06-30');
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $otherYear);

        $result = app(UniformProductCatalogSyncService::class)->sync($targetYear, apply: true);

        $this->assertSame(40, count($result['missing']), 'the target year has no source rows of its own');
        $this->assertSame(0, DB::table('uniform_products')->count());
    }

    // ------------------------------------------------------------------
    // 7. Non-Uniform FeePrices untouched.
    // ------------------------------------------------------------------
    public function test_non_uniform_feeprices_untouched(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);
        $registrationFee = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'type' => 'yearly', 'payment_period' => Fee::PERIOD_ONCE, 'amount' => '0.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $registrationFee->id, 'academic_year_id' => $year->id, 'amount' => '7000.00', 'currency' => 'EGP', 'payment_period' => Fee::PERIOD_YEARLY, 'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true]);

        app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        $this->assertSame(1, FeePrice::where('fee_id', $registrationFee->id)->count());
        $this->assertSame('7000.00', number_format((float) FeePrice::where('fee_id', $registrationFee->id)->first()->getRawOriginal('amount'), 2, '.', ''));
    }

    // ------------------------------------------------------------------
    // 8. Transport/Food/MealPlan/PaymentPlan data untouched.
    // ------------------------------------------------------------------
    public function test_transport_food_mealplan_paymentplan_data_untouched(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);
        DB::table('transport_routes')->insert(['name' => 'Фикстура маршрута', 'created_at' => now(), 'updated_at' => now()]);
        $mealPlan = MealPlan::create(['name_ru' => 'Фикстура питания', 'meal_type' => MealPlan::TYPE_BOTH, 'period' => MealPlan::PERIOD_DAILY, 'price' => '100.00', 'is_active' => true]);
        $paymentPlan = PaymentPlan::create(['name_ru' => 'Фикстура плана', 'is_active' => true, 'sort_order' => 0]);

        app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        $this->assertSame(1, DB::table('transport_routes')->count());
        $this->assertSame(1, MealPlan::count());
        $this->assertTrue($mealPlan->refresh()->is_active);
        $this->assertSame(1, PaymentPlan::count());
        $this->assertTrue($paymentPlan->refresh()->is_active);
    }

    // ------------------------------------------------------------------
    // 9. Missing one canonical pair => fail closed, zero writes.
    // ------------------------------------------------------------------
    public function test_missing_one_canonical_pair_fails_closed_with_zero_writes(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year, skip: ['Толстовка|XL']);

        $result = app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        $this->assertSame(['Толстовка|XL'], $result['missing']);
        $this->assertFalse($result['applied']);
        $this->assertSame(0, DB::table('uniform_products')->count());
    }

    // ------------------------------------------------------------------
    // 10. Duplicate active FeePrices for same pair => fail closed, zero writes.
    // ------------------------------------------------------------------
    public function test_duplicate_active_feeprices_for_same_pair_fails_closed(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '999.00', 'currency' => 'EGP',
            'item' => 'Майка', 'size' => '14', 'payment_period' => Fee::PERIOD_ONCE,
            'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true,
        ]);

        $result = app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        $this->assertContains('Майка|14', $result['ambiguous_source_pairs']);
        $this->assertFalse($result['applied']);
        $this->assertSame(0, DB::table('uniform_products')->count());
    }

    // ------------------------------------------------------------------
    // 11. Existing inactive product => reactivated, not duplicated.
    // ------------------------------------------------------------------
    public function test_existing_inactive_product_is_reactivated_not_duplicated(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);
        $id = DB::table('uniform_products')->insertGetId(['name_ru' => 'Майка', 'category' => 'uniform', 'size' => '14', 'price' => '1.00', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);

        $result = app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        $this->assertSame(1, $result['reactivated']);
        $this->assertSame(39, $result['created']);
        $row = DB::table('uniform_products')->find($id);
        $this->assertTrue((bool) $row->is_active);
        $this->assertSame(1, DB::table('uniform_products')->where('name_ru', 'Майка')->where('size', '14')->count());
    }

    // ------------------------------------------------------------------
    // 12. Existing active product => unchanged.
    // ------------------------------------------------------------------
    public function test_existing_active_product_is_unchanged(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);
        $originalTimestamp = now()->subDay();
        $id = DB::table('uniform_products')->insertGetId(['name_ru' => 'Майка', 'category' => 'uniform', 'size' => '14', 'price' => '500.00', 'is_active' => true, 'created_at' => $originalTimestamp, 'updated_at' => $originalTimestamp]);

        $result = app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        $this->assertSame(40, $result['unchanged'] + $result['created'] + $result['reactivated']);
        $this->assertGreaterThanOrEqual(1, $result['unchanged']);
        $row = DB::table('uniform_products')->find($id);
        $this->assertTrue((bool) $row->is_active);
        $this->assertSame($originalTimestamp->toDateTimeString(), \Carbon\Carbon::parse($row->updated_at)->toDateTimeString(), 'an already-active matching row must never be written to');
    }

    // ------------------------------------------------------------------
    // 13. Stale catalog product => deactivated, not deleted.
    // ------------------------------------------------------------------
    public function test_stale_catalog_product_is_deactivated_not_deleted(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);
        $staleId = DB::table('uniform_products')->insertGetId(['name_ru' => 'Кепка', 'category' => 'uniform', 'size' => 'M', 'price' => '50.00', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $result = app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        $this->assertSame(1, $result['deactivated']);
        $stale = DB::table('uniform_products')->find($staleId);
        $this->assertNotNull($stale, 'stale product must never be deleted');
        $this->assertFalse((bool) $stale->is_active);
    }

    // ------------------------------------------------------------------
    // 14. Duplicate uniform_products identity => fail closed before any write.
    // ------------------------------------------------------------------
    public function test_duplicate_uniform_products_identity_fails_closed_before_any_write(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);
        DB::table('uniform_products')->insert(['name_ru' => 'Майка', 'category' => 'uniform', 'size' => '14', 'price' => '500.00', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('uniform_products')->insert(['name_ru' => 'Майка', 'category' => 'uniform', 'size' => '14', 'price' => '500.00', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);

        $result = app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        $this->assertContains('Майка|14', $result['duplicate_catalog_pairs']);
        $this->assertFalse($result['applied']);
        // Nothing was written or altered beyond the two pre-existing fixture rows.
        $this->assertSame(2, DB::table('uniform_products')->where('name_ru', 'Майка')->where('size', '14')->count());
        $this->assertSame(0, DB::table('uniform_products')->where('name_ru', '!=', 'Майка')->count());
    }

    // ------------------------------------------------------------------
    // 15. Explicit year-id works correctly when two AcademicYears share a name.
    // ------------------------------------------------------------------
    public function test_explicit_year_id_works_when_two_academic_years_share_a_name(): void
    {
        $canonical = $this->makeYear('2026/2027', '2026-09-01', '2027-06-30');
        $duplicate = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => false]);
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $canonical);

        $result = app(UniformProductCatalogSyncService::class)->sync($canonical, apply: true);

        $this->assertSame($canonical->id, $result['academic_year_id']);
        $this->assertNotSame($duplicate->id, $result['academic_year_id']);
        $this->assertSame(40, $result['created']);

        Artisan::call('finance:sync-uniform-products', ['--year-id' => $duplicate->id, '--dry-run' => true]);
        $duplicateOutput = Artisan::output();
        $this->assertStringContainsString((string) $duplicate->id, $duplicateOutput);
    }

    // ------------------------------------------------------------------
    // 16. uniform_products.price is not the invoice pricing authority.
    // ------------------------------------------------------------------
    public function test_uniform_products_price_is_not_the_invoice_pricing_authority(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);
        app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        // Deliberately corrupt the catalog's stored price to a value that
        // does NOT match the current FeePrice amount.
        DB::table('uniform_products')->where('name_ru', 'Майка')->where('size', '14')->update(['price' => '1.00']);

        $calculation = app(InvoiceCalculationService::class)->calculate(
            items: [['fee_id' => $fee->id, 'quantity' => 1, 'item' => 'Майка', 'size' => '14']],
            pricingDate: $year->start_date->toDateString(),
            academicYearId: $year->id,
        );

        $line = collect($calculation['line_items'])->first();
        $this->assertSame('500.00', number_format((float) $line['amount'], 2, '.', ''), 'invoice pricing must come from FeePrice, never the corrupted uniform_products.price');
    }

    // ------------------------------------------------------------------
    // 17. Quick Registration remains compatible with sync-created rows.
    // ------------------------------------------------------------------
    public function test_quick_registration_compatible_with_sync_created_rows(): void
    {
        $year = $this->makeYear();
        $year->update(['is_active' => true]);
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);
        app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        $product = DB::table('uniform_products')->where('name_ru', 'Поло')->where('size', '16')->where('is_active', true)->first();
        $this->assertNotNull($product, 'a sync-created row must be selectable by Quick Registration exactly like any other active uniform_products row');
        $this->assertSame('Поло', $product->name_ru);
        $this->assertSame('16', $product->size);
    }

    // ------------------------------------------------------------------
    // 18. Procurement reporting remains historical-metadata-driven and unaffected.
    // ------------------------------------------------------------------
    public function test_procurement_reporting_unaffected_by_sync(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);
        app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        Artisan::call('finance:uniform-procurement-report');
        $before = Artisan::output();

        // Deactivate everything the sync just created.
        DB::table('uniform_products')->update(['is_active' => false]);

        Artisan::call('finance:uniform-procurement-report');
        $after = Artisan::output();

        $this->assertSame($before, $after, 'procurement report reads only invoice_items.metadata, never the live uniform_products catalog');
    }

    // ------------------------------------------------------------------
    // 19. Transaction rollback leaves zero partial mutations if apply fails.
    // ------------------------------------------------------------------
    public function test_transaction_rollback_leaves_zero_partial_mutations_if_apply_fails(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);

        // SQLite quotes identifiers with double quotes, not backticks — the
        // check below is quote-style-agnostic on purpose.
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into')
                && str_contains($query->sql, 'uniform_products')
                && DB::table('uniform_products')->count() >= 5) {
                throw new RuntimeException('simulated failure mid-transaction');
            }
        });

        $thrown = null;
        try {
            app(UniformProductCatalogSyncService::class)->sync($year, apply: true);
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, 'expected the simulated failure to propagate');
        $this->assertStringContainsString('simulated failure', $thrown->getMessage());

        $this->assertSame(0, DB::table('uniform_products')->count(), 'a mid-transaction failure must leave zero partial rows');
    }

    // ------------------------------------------------------------------
    // 20. Exact Cartesian matrix validation: 40 raw rows with a duplicated
    // pair and a missing different pair must FAIL, even though the raw
    // row count is still 40.
    // ------------------------------------------------------------------
    public function test_exact_cartesian_matrix_validation_catches_duplicate_plus_missing_at_same_row_count(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        // Seed 39 of the 40 pairs (omit Толстовка|XL), then add a second
        // active row for a DIFFERENT pair (Майка|14) so the raw row count
        // is still exactly 40, but the matrix is neither complete nor
        // unambiguous.
        $this->seedFullExactMatrix($fee, $year, skip: ['Толстовка|XL']);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '500.00', 'currency' => 'EGP',
            'item' => 'Майка', 'size' => '14', 'payment_period' => Fee::PERIOD_ONCE,
            'start_date' => $year->start_date->toDateString(), 'end_date' => $year->end_date->toDateString(), 'is_active' => true,
        ]);

        $this->assertSame(40, FeePrice::where('fee_id', $fee->id)->where('is_active', true)->count(), 'sanity check: raw active row count is 40');

        $result = app(UniformProductCatalogSyncService::class)->sync($year, apply: true);

        $this->assertContains('Толстовка|XL', $result['missing']);
        $this->assertContains('Майка|14', $result['ambiguous_source_pairs']);
        $this->assertFalse($result['applied']);
        $this->assertSame(0, DB::table('uniform_products')->count());
    }

    // ------------------------------------------------------------------
    // Command-level: explicit --year-id is required, never a name.
    // ------------------------------------------------------------------
    public function test_command_refuses_to_run_without_year_id(): void
    {
        $exitCode = Artisan::call('finance:sync-uniform-products', ['--dry-run' => true]);

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(0, DB::table('uniform_products')->count());
    }

    public function test_command_refuses_nonexistent_year_id(): void
    {
        $exitCode = Artisan::call('finance:sync-uniform-products', ['--year-id' => 999999, '--dry-run' => true]);

        $this->assertNotSame(0, $exitCode);
    }

    public function test_command_refuses_both_dry_run_and_apply_together(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);

        $exitCode = Artisan::call('finance:sync-uniform-products', ['--year-id' => $year->id, '--dry-run' => true, '--apply' => true]);

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(0, DB::table('uniform_products')->count());
    }

    public function test_command_defaults_to_dry_run_when_neither_mode_is_passed(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);

        Artisan::call('finance:sync-uniform-products', ['--year-id' => $year->id]);

        $this->assertSame(0, DB::table('uniform_products')->count(), 'omitting both flags must never silently apply');
    }

    public function test_command_apply_with_explicit_year_id_creates_products(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year);

        $exitCode = Artisan::call('finance:sync-uniform-products', ['--year-id' => $year->id, '--apply' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(40, DB::table('uniform_products')->where('is_active', true)->count());
    }

    public function test_command_fails_and_writes_nothing_when_matrix_incomplete(): void
    {
        $year = $this->makeYear();
        $fee = $this->makeUniformFee();
        $this->seedFullExactMatrix($fee, $year, skip: ['Комплект|S']);

        $exitCode = Artisan::call('finance:sync-uniform-products', ['--year-id' => $year->id, '--apply' => true]);

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(0, DB::table('uniform_products')->count());
    }
}
