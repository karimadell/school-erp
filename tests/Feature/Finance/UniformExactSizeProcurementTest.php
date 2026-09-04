<?php

namespace Tests\Feature\Finance;

use App\Console\Commands\UniformProcurementReport;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use App\Services\Finance\SchoolPriceListImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Uniform exact-size corrective pass.
 *
 * Root cause: SchoolPriceListImportService::uniformVariants() hardcoded
 * three broad size-TIER labels ('6–10', '12–16', 'от S') as the literal
 * FeePrice.size/uniform_products.size value — a factory procurement order
 * cannot be built from a range. uniformVariants() is left completely
 * unmodified (legacy rows/history stay exactly as they were); a new,
 * purely-additive uniformIndividualSizeVariants() imports individual exact
 * sizes (6/8/10/12/14/16, S/M/L/XL) alongside the legacy rows. No
 * controller/blade change was needed: item/size are already free-text
 * dimensions the existing Quick Registration selector and pricing resolver
 * handle generically, and uniform_products is already self-derived from
 * FeePrice item/size pairs by UatMasterDataRepair — an exact-size FeePrice
 * row flows through entirely unchanged code.
 */
class UniformExactSizeProcurementTest extends TestCase
{
    use RefreshDatabase;

    protected User $accountant;

    protected array $base;

    protected AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');

        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $stage->id, 'level' => 1]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'А', 'name_ru' => 'А', 'name_ar' => 'A', 'is_active' => true]);
        $mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очная форма', 'is_active' => true]);

        $this->base = [
            'student_last_name_ru' => 'Иванова', 'student_first_name_ru' => 'Анна',
            'phone' => '+20 100 555 7788', 'registration_date' => '2026-08-15',
            'academic_year_id' => $this->year->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id,
            'class_id' => $class->id, 'enrollment_mode_id' => $mode->id,
        ];

        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);
    }

    private function uniformFee(): Fee
    {
        return Fee::firstOrCreate(
            ['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM],
            ['amount' => '0.00', 'is_active' => true],
        );
    }

    private function uniformTariff(Fee $fee, string $item, string $size, string $amount): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
            'item' => $item, 'size' => $size,
        ]);
    }

    private function uniformProduct(string $item, string $size, string $price): int
    {
        return DB::table('uniform_products')->insertGetId([
            'name_ru' => $item, 'category' => 'garment', 'size' => $size, 'price' => $price,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // 1 & 8. exact numeric size selectable + price still resolves correctly
    // ------------------------------------------------------------------
    public function test_exact_numeric_size_is_selectable_and_prices_correctly(): void
    {
        $fee = $this->uniformFee();
        $this->uniformTariff($fee, 'Майка', '8', '400.00');
        $productId = $this->uniformProduct('Майка', '8', '400.00');

        $page = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));
        $page->assertOk();
        // The uniform <select> renders one <option> per sellable uniform_products row.
        $page->assertSee('value="'.$productId.'"', false);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.price'), [
            'fee_id' => $fee->id, 'quantity' => 3, 'item' => 'Майка', 'size' => '8',
            'academic_year_id' => $this->year->id, 'enrollment_mode_id' => \App\Models\EnrollmentMode::first()->id,
            'registration_date' => '2026-08-15',
        ]);
        $response->assertOk();
        $this->assertSame('400.00', $response->json('unit_price'));
        $this->assertSame('1200.00', $response->json('amount'));
    }

    // ------------------------------------------------------------------
    // 2. exact letter size selectable where configured
    // ------------------------------------------------------------------
    public function test_exact_letter_size_is_selectable(): void
    {
        $fee = $this->uniformFee();
        $this->uniformTariff($fee, 'Толстовка', 'M', '1500.00');
        $productId = $this->uniformProduct('Толстовка', 'M', '1500.00');

        $page = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));
        $page->assertOk();
        $page->assertSee('value="'.$productId.'"', false);
        $page->assertSee('data-size="M"', false);
    }

    // ------------------------------------------------------------------
    // 3. invoice line persists exact size in an auditable way
    // ------------------------------------------------------------------
    public function test_invoice_line_persists_the_exact_selected_size(): void
    {
        $fee = $this->uniformFee();
        $this->uniformTariff($fee, 'Поло', '10', '700.00');
        $productId = $this->uniformProduct('Поло', '10', '700.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'services' => [[
                'fee_id' => $fee->id, 'quantity' => 2, 'paid_now' => '0.00',
                'item' => 'Поло', 'size' => '10', 'uniform_product_id' => $productId,
            ]],
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect();

        $invoiceItem = InvoiceItem::where('fee_id', $fee->id)->sole();
        $this->assertSame('Поло', $invoiceItem->metadata['item']);
        $this->assertSame('10', $invoiceItem->metadata['size']);
        $this->assertSame($productId, $invoiceItem->metadata['uniform_product_id']);
        $this->assertSame(2, $invoiceItem->quantity);
    }

    // ------------------------------------------------------------------
    // 4 & 5. procurement report aggregation — different sizes separate,
    // same size aggregates
    // ------------------------------------------------------------------
    public function test_report_aggregates_same_size_together_and_different_sizes_separately(): void
    {
        $fee = $this->uniformFee();
        $this->uniformTariff($fee, 'Майка', '6', '400.00');
        $this->uniformTariff($fee, 'Майка', '8', '400.00');
        $product6 = $this->uniformProduct('Майка', '6', '400.00');
        $product8 = $this->uniformProduct('Майка', '8', '400.00');

        // Two separate size-6 sales (different students) — must aggregate to one row, quantity 5.
        $this->issueUniformInvoice($fee->id, 'Майка', '6', $product6, 2);
        $this->issueUniformInvoice($fee->id, 'Майка', '6', $product6, 3);
        // One size-8 sale — must remain its own separate row, quantity 4.
        $this->issueUniformInvoice($fee->id, 'Майка', '8', $product8, 4);

        // P1 (code review): assert the COMMAND'S OWN rendered table, not a
        // parallel Eloquent recomputation — this is the only thing that
        // actually proves the report's own grouping/summing code is
        // correct, not just that the underlying data was set up correctly.
        $exitCode = Artisan::call('finance:uniform-procurement-report', ['--year' => '2026/2027']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $rows = $this->tableRows($output);
        $this->assertSame('5', $rows['Майка|6'] ?? null, "size-6 row missing or wrong quantity in report output:\n{$output}");
        $this->assertSame('4', $rows['Майка|8'] ?? null, "size-8 row missing or wrong quantity in report output:\n{$output}");
    }

    // ------------------------------------------------------------------
    // 6. historical grouped-size invoice remains readable
    // ------------------------------------------------------------------
    public function test_historical_grouped_size_invoice_remains_readable_and_is_not_reinterpreted(): void
    {
        $fee = $this->uniformFee();
        // A legacy grouped-range tariff, exactly as uniformVariants() would
        // have produced before this corrective pass — never modified.
        $legacyPrice = $this->uniformTariff($fee, 'Толстовка', '6–10', '900.00');
        $legacyProductId = $this->uniformProduct('Толстовка', '6–10', '900.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'services' => [[
                'fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00',
                'item' => 'Толстовка', 'size' => '6–10', 'uniform_product_id' => $legacyProductId,
            ]],
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect();

        $invoiceItem = InvoiceItem::where('fee_id', $fee->id)->sole();
        $this->assertSame('6–10', $invoiceItem->metadata['size']);
        $this->assertSame(0, bccomp((string) $legacyPrice->getRawOriginal('amount'), (string) $invoiceItem->getRawOriginal('amount'), 2));

        // The report must read it without error, must NOT fold it into any
        // exact-size row, and must visibly mark it as a legacy group in
        // its own rendered output (P1 — asserted on the real table, not a
        // side assertion).
        $exitCode = Artisan::call('finance:uniform-procurement-report');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $rows = $this->tableRows($output);
        $flags = $this->tableLegacyFlags($output);
        $this->assertSame('1', $rows['Толстовка|6–10'] ?? null, "legacy row missing from report output:\n{$output}");
        $this->assertTrue($flags['Толстовка|6–10'] ?? false, "legacy row not flagged as legacy in report output:\n{$output}");
    }

    // ------------------------------------------------------------------
    // 7. cancelled/refunded line does not inflate procurement quantity
    // ------------------------------------------------------------------
    public function test_cancelled_invoice_line_is_excluded_from_procurement_quantity(): void
    {
        $fee = $this->uniformFee();
        $this->uniformTariff($fee, 'Майка', '12', '500.00');
        $productId = $this->uniformProduct('Майка', '12', '500.00');

        $keptInvoiceId = $this->issueUniformInvoice($fee->id, 'Майка', '12', $productId, 3);
        $cancelledInvoiceId = $this->issueUniformInvoice($fee->id, 'Майка', '12', $productId, 10);
        Invoice::whereKey($cancelledInvoiceId)->update(['status' => Invoice::STATUS_CANCELLED]);

        // P1 (code review): assert the command's OWN reported quantity is
        // 3 (kept invoice only) — not 13 (both) — proven from its real
        // output, not a hand-rolled parallel query.
        $exitCode = Artisan::call('finance:uniform-procurement-report');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $rows = $this->tableRows($output);
        $this->assertSame('3', $rows['Майка|12'] ?? null, "expected quantity 3 (cancelled invoice's 10 excluded) in report output:\n{$output}");
        $this->assertStringNotContainsString('13', $output);
    }

    // ==================================================================
    // Code review P0 — legacy grouped-size rows must not remain sellable
    // for new sales once exact-size replacements exist for that Fee/year.
    // These tests drive the REAL SchoolPriceListImportService::import(),
    // not hand-built fixtures, so they exercise the actual production
    // import path the P0 fix lives in.
    // ==================================================================

    private function runRealImport(): array
    {
        // Dates bracket "now" dynamically (not a fixed range) so this
        // fixture's tariffs are always currently-resolvable regardless of
        // when the suite actually runs — SchoolPriceListImportService
        // copies the AcademicYear's own start_date/end_date directly onto
        // every imported FeePrice row's validity window.
        AcademicYear::firstOrCreate(
            ['name' => SchoolPriceListImportService::YEAR],
            ['start_date' => now()->subMonths(6)->toDateString(), 'end_date' => now()->addMonths(6)->toDateString(), 'is_active' => true],
        );

        return app(SchoolPriceListImportService::class)->import();
    }

    private function uniformFeeAndYearAfterImport(): array
    {
        $fee = Fee::where('name_ru', 'Школьная форма')->where('category', Fee::CATEGORY_UNIFORM)->firstOrFail();
        $year = AcademicYear::where('name', SchoolPriceListImportService::YEAR)->firstOrFail();

        return [$fee, $year];
    }

    /** 1. Exact-size rows are active after import. */
    public function test_exact_size_rows_are_active_after_import(): void
    {
        $this->runRealImport();
        [$fee, $year] = $this->uniformFeeAndYearAfterImport();

        $exactSizes = ['6', '8', '10', '12', '14', '16', 'S', 'M', 'L', 'XL'];
        $activeCount = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->whereIn('size', $exactSizes)->where('is_active', true)->count();

        // 10 exact sizes x 4 items (Комплект/Майка/Поло/Толстовка) = 40.
        $this->assertSame(40, $activeCount);
    }

    /** 2. Legacy grouped rows for the SAME imported academic year are inactive after import. */
    public function test_legacy_grouped_rows_are_inactive_after_import(): void
    {
        $this->runRealImport();
        [$fee, $year] = $this->uniformFeeAndYearAfterImport();

        $legacySizes = ['6–10', '12–16', 'от S'];
        $legacyRows = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->whereIn('size', $legacySizes)->get();

        // All 12 legacy rows (3 tiers x 4 items) must still EXIST...
        $this->assertCount(12, $legacyRows);
        // ...but none may remain active.
        $this->assertSame(0, $legacyRows->where('is_active', true)->count());
        $this->assertSame(12, $legacyRows->where('is_active', false)->count());
        // Deactivation must never rewrite size/amount/item.
        $this->assertTrue($legacyRows->every(fn (FeePrice $p) => in_array($p->size, $legacySizes, true)));
    }

    /** 3. A historical invoice referencing a now-inactive grouped FeePrice remains fully readable. */
    public function test_historical_invoice_referencing_now_inactive_grouped_feeprice_remains_readable(): void
    {
        $this->runRealImport();
        [$fee, $year] = $this->uniformFeeAndYearAfterImport();

        $legacyPrice = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->where('item', 'Майка')->where('size', '6–10')->sole();
        $this->assertFalse($legacyPrice->is_active);

        $productId = $this->uniformProduct('Майка', '6–10', $legacyPrice->getRawOriginal('amount'));
        $invoiceId = $this->issueUniformInvoice($fee->id, 'Майка', '6–10', $productId, 1);

        $invoice = \App\Models\Invoice::with('items')->findOrFail($invoiceId);
        $this->assertNotEmpty($invoice->items);
        $this->assertSame('Майка', $invoice->items->first()->metadata['item']);
        $this->assertSame('6–10', $invoice->items->first()->metadata['size']);
        $this->assertSame('Школьная форма', $invoice->items->first()->fee->name_ru);
    }

    /** 4 & 5. Quick Registration does NOT render legacy grouped sizes, and DOES render exact sizes, after import. */
    public function test_quick_registration_hides_legacy_grouped_sizes_and_shows_exact_sizes_after_import(): void
    {
        $this->runRealImport();
        // Quick Registration filters FeePrice by resolvableCandidates() for
        // the screen's ACTIVE academic years — the imported 2025/2026 year
        // is inactive by default in this fixture, so activate it (mirrors
        // how a real deployment would already have its target year active).
        AcademicYear::where('name', SchoolPriceListImportService::YEAR)->update(['is_active' => true]);
        [$fee, $year] = $this->uniformFeeAndYearAfterImport();

        // uniform_products is self-derived from FeePrice item|size pairs —
        // seed it the same way UatMasterDataRepair would, for every row
        // (legacy AND exact) so the selector has something to filter.
        foreach (FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->get() as $price) {
            $this->uniformProduct($price->item, $price->size, (string) $price->getRawOriginal('amount'));
        }

        $page = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));
        $page->assertOk();

        $html = $page->getContent();
        // Legacy grouped values must never render as a selectable option.
        $this->assertStringNotContainsString('data-size="6–10"', $html);
        $this->assertStringNotContainsString('data-size="12–16"', $html);
        $this->assertStringNotContainsString('data-size="от S"', $html);
        // Exact sizes must render.
        $this->assertStringContainsString('data-size="6"', $html);
        $this->assertStringContainsString('data-size="S"', $html);
    }

    /** 6. A second import() run is idempotent: no duplicate exact-size rows, legacy stays inactive, exact stays active. */
    public function test_second_import_run_is_idempotent_for_uniform_rows(): void
    {
        $first = $this->runRealImport();
        [$fee, $year] = $this->uniformFeeAndYearAfterImport();
        $countAfterFirst = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->count();
        $this->assertSame(52, $countAfterFirst); // 12 legacy + 40 exact

        $second = app(SchoolPriceListImportService::class)->import();

        $this->assertSame(0, $second['tariffs_created'], 'second import() must create zero new tariffs across the whole catalog');
        $countAfterSecond = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->count();
        $this->assertSame(52, $countAfterSecond, 'second import() must not duplicate any Uniform row');

        $legacyActiveCount = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->whereIn('size', ['6–10', '12–16', 'от S'])->where('is_active', true)->count();
        $exactActiveCount = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->whereIn('size', ['6', '8', '10', '12', '14', '16', 'S', 'M', 'L', 'XL'])->where('is_active', true)->count();
        $this->assertSame(0, $legacyActiveCount, 'legacy rows must remain inactive after a second run');
        $this->assertSame(40, $exactActiveCount, 'exact rows must remain active after a second run');
    }

    /** 7. Another academic year's legacy grouped rows are NOT deactivated unless that year is processed. */
    public function test_other_academic_years_legacy_rows_are_not_deactivated(): void
    {
        $otherYear = AcademicYear::create(['name' => '2099/2100', 'start_date' => '2099-08-01', 'end_date' => '2100-06-30', 'is_active' => false]);
        $fee = Fee::firstOrCreate(
            ['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM],
            ['amount' => '0.00', 'is_active' => true],
        );
        // A legacy-shaped row for a DIFFERENT year import() never targets
        // (import() only ever processes SchoolPriceListImportService::YEAR).
        $unrelatedLegacyRow = FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $otherYear->id, 'amount' => '900.00', 'currency' => 'EGP',
            'start_date' => $otherYear->start_date, 'end_date' => $otherYear->end_date, 'is_active' => true,
            'item' => 'Толстовка', 'size' => '6–10',
        ]);

        $this->runRealImport();

        $this->assertTrue($unrelatedLegacyRow->fresh()->is_active, "a different academic year's legacy row must never be touched by import()");
    }

    /** Minimal direct-issuance helper — bypasses the full HTTP submission for pure data setup in aggregation tests. */
    private function issueUniformInvoice(int $feeId, string $item, string $size, int $productId, int $quantity): int
    {
        $invoice = Invoice::create([
            'student_id' => $this->makeStudent()->id,
            'academic_year_id' => $this->year->id,
            'status' => Invoice::STATUS_UNPAID,
            'total_amount' => '0.00',
            'paid_amount' => '0.00',
            'remaining_amount' => '0.00',
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'fee_id' => $feeId, 'description' => $item,
            'amount' => '0.00', 'unit_price' => '0.00', 'quantity' => $quantity,
            'paid_amount' => '0.00', 'remaining_amount' => '0.00',
            'metadata' => ['item' => $item, 'size' => $size, 'uniform_product_id' => $productId],
        ]);

        return $invoice->id;
    }

    private function makeStudent(): \App\Models\Student
    {
        return \App\Models\Student::create(['name' => 'Тестовый ученик '.uniqid()]);
    }

    /**
     * Parses finance:uniform-procurement-report's own rendered
     * `item | size | quantity | legacy grouped size?` table into
     * ['item|size' => quantity] so tests can assert on the command's
     * REAL output rather than a parallel Eloquent recomputation (code
     * review P1). Skips border/header lines; tolerant of the Cyrillic
     * item names and multi-byte dash in legacy size values.
     *
     * @return array<string,string>
     */
    private function tableRows(string $output): array
    {
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            if (count($cells) < 3 || $cells[0] === 'item') {
                continue;
            }
            $rows[$cells[0].'|'.$cells[1]] = $cells[2];
        }

        return $rows;
    }

    /** True if the given item|size row's "legacy grouped size?" cell says YES. */
    private function tableLegacyFlags(string $output): array
    {
        $flags = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            if (count($cells) < 4 || $cells[0] === 'item') {
                continue;
            }
            $flags[$cells[0].'|'.$cells[1]] = str_starts_with($cells[3], 'YES');
        }

        return $flags;
    }
}
