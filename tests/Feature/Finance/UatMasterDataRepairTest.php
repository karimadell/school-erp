<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\MealPlan;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Services\Finance\FinanceConfigurationReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 4B — finance:uat-master-data-repair. Default dry-run, --apply
 * required to write, entire write wrapped in one transaction, idempotent
 * on re-run. Confirmed UAT decision: the three a-la-carte legacy Food names
 * (Суп/Второе блюдо/Напиток) are permanently excluded from MealPlan
 * creation/linking — reported as SKIPPED — LEGACY A-LA-CARTE / OUTSIDE
 * CURRENT QUICK REGISTRATION MEALPLAN MODEL, left completely untouched,
 * and never block the rest of --apply (Transport + the 3 valid MealPlans
 * + Uniform + the UAT installment plan).
 */
class UatMasterDataRepairTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;
    private Fee $foodFee;
    private Fee $uniformFee;
    private Fee $transportFee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true]);
        // Food flexible-duration corrective pass: FinanceConfigurationReadinessService::assessFood()
        // additionally requires an AcademicCalendar for the year (Food's day-count calculator needs one).
        \App\Models\AcademicCalendar::create(['academic_year_id' => $this->year->id, 'weekly_days_off' => ['fri', 'sat']]);

        $this->foodFee = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
        foreach ([
            'Комплексное питание' => '170.00', 'Завтрак' => '70.00', 'Обед' => '100.00',
            'Суп' => '20.00', 'Второе блюдо' => '80.00', 'Напиток' => '10.00',
        ] as $name => $amount) {
            $this->foodPrice($amount, $name);
        }

        $this->uniformFee = Fee::create(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '1.00', 'is_active' => true]);
        $this->uniformPrice('2000.00', 'Комплект', '6–10');
        $this->uniformPrice('400.00', 'Майка', '6–10');

        $this->transportFee = Fee::create(['name_ru' => 'Транспорт', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $this->transportPrice('13500.00', 'Зона 1');
    }

    private function foodPrice(string $amount, string $optionValue): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $this->foodFee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => $optionValue, 'payment_period' => 'daily',
        ]);
    }

    private function uniformPrice(string $amount, string $item, string $size): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $this->uniformFee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'item' => $item, 'size' => $size,
        ]);
    }

    private function transportPrice(string $amount, string $zone): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $this->transportFee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'zone', 'option_value' => $zone,
        ]);
    }

    private function counts(): array
    {
        return [
            'transport_routes' => DB::table('transport_routes')->count(),
            'meal_plans' => MealPlan::count(),
            'uniform_products' => DB::table('uniform_products')->count(),
            'payment_plans' => PaymentPlan::count(),
            'payment_plan_installments' => PaymentPlanInstallment::count(),
            'fee_prices' => FeePrice::count(),
            'invoices' => Invoice::count(),
        ];
    }

    // ----- 1. Default is dry-run, zero writes ---------------------------------

    public function test_default_command_makes_zero_writes(): void
    {
        $before = $this->counts();
        $foodBefore = FeePrice::where('fee_id', $this->foodFee->id)->pluck('option_value', 'id')->all();

        $exitCode = Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('DRY-RUN', $output);
        $this->assertStringContainsString('DRY-RUN ONLY — no data was created', $output);
        $this->assertSame($before, $this->counts());
        $this->assertSame($foodBefore, FeePrice::where('fee_id', $this->foodFee->id)->pluck('option_value', 'id')->all());
    }

    // ----- 2. --apply is transactional ----------------------------------------

    public function test_apply_is_transactional_and_rolls_back_entirely_on_failure(): void
    {
        $before = $this->counts();

        // Force a failure on the very last category written (installments),
        // after transport/food/uniform have already been written earlier in
        // the SAME call — if the transaction is real, none of it survives.
        Event::listen('eloquent.created: '.PaymentPlan::class, function (): void {
            throw new RuntimeException('Simulated failure to prove transactional rollback.');
        });

        try {
            Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027', '--apply' => true]);
            $this->fail('Expected the simulated exception to propagate out of the transaction.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure to prove transactional rollback.', $e->getMessage());
        }

        $this->assertSame($before, $this->counts());
    }

    // ----- 3. Second --apply is idempotent ------------------------------------

    public function test_a_second_apply_makes_no_further_writes(): void
    {
        Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027', '--apply' => true]);
        $afterFirst = $this->counts();
        $this->assertGreaterThan(0, $afterFirst['transport_routes']);
        $this->assertGreaterThan(0, $afterFirst['meal_plans']);
        $this->assertGreaterThan(0, $afterFirst['uniform_products']);
        $this->assertSame(1, $afterFirst['payment_plans']);

        $exitCode = Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027', '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame($afterFirst, $this->counts());
        $this->assertStringContainsString('already exists', $output);
    }

    // ----- 4. Existing FeePrice ids/amounts stay unchanged ----------------------

    public function test_existing_fee_price_ids_and_amounts_are_unchanged_after_apply(): void
    {
        $before = FeePrice::orderBy('id')->get(['id', 'amount', 'fee_id', 'academic_year_id', 'start_date', 'end_date'])
            ->map(fn (FeePrice $p) => $p->only(['id', 'amount', 'fee_id', 'academic_year_id']))->all();

        Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027', '--apply' => true]);

        $after = FeePrice::orderBy('id')->get(['id', 'amount', 'fee_id', 'academic_year_id', 'start_date', 'end_date'])
            ->map(fn (FeePrice $p) => $p->only(['id', 'amount', 'fee_id', 'academic_year_id']))->all();

        $this->assertSame($before, $after);
        $this->assertSame(count($before), FeePrice::count(), 'no FeePrice row may be created or deleted');
    }

    // ----- 3/6. The 3 valid MealPlans are created and linked; Food option_value is
    //            the only modified Food tariff field, only for resolvable names ---

    public function test_food_option_value_is_the_only_field_changed_and_only_for_resolvable_names(): void
    {
        Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027', '--apply' => true]);

        $plans = MealPlan::pluck('id', 'name_ru');
        $this->assertCount(3, $plans, 'only the 3 shape-compatible names get a MealPlan');
        $this->assertTrue($plans->has('Комплексное питание'));
        $this->assertTrue($plans->has('Завтрак'));
        $this->assertTrue($plans->has('Обед'));
        $this->assertSame(MealPlan::TYPE_BOTH, MealPlan::find($plans['Комплексное питание'])->meal_type);
        $this->assertSame(MealPlan::TYPE_BREAKFAST, MealPlan::find($plans['Завтрак'])->meal_type);
        $this->assertSame(MealPlan::TYPE_LUNCH, MealPlan::find($plans['Обед'])->meal_type);

        foreach (['Комплексное питание' => '170.00', 'Завтрак' => '70.00', 'Обед' => '100.00'] as $name => $amount) {
            $row = FeePrice::where('fee_id', $this->foodFee->id)->where('amount', $amount)->sole();
            $this->assertSame((string) $plans[$name], $row->option_value);
            $this->assertSame('meal_plan', $row->option_type);
            $this->assertSame($amount, $row->amount);
        }
    }

    // ----- 1. The 3 a-la-carte rows are skipped and unchanged --------------------

    public function test_the_three_a_la_carte_rows_are_skipped_and_left_completely_unchanged(): void
    {
        $skippedBefore = FeePrice::where('fee_id', $this->foodFee->id)
            ->whereIn('option_value', ['Суп', 'Второе блюдо', 'Напиток'])
            ->get(['id', 'option_type', 'option_value', 'amount'])
            ->keyBy('option_value');
        $this->assertCount(3, $skippedBefore);

        Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027', '--apply' => true]);

        foreach (['Суп', 'Второе блюдо', 'Напиток'] as $name) {
            $row = FeePrice::find($skippedBefore[$name]->id);
            $this->assertNotNull($row, "{$name}'s FeePrice row must not be deleted");
            $this->assertSame('meal_plan', $row->option_type, "{$name}: option_type must stay 'meal_plan'");
            $this->assertSame($name, $row->option_value, "{$name}: option_value must stay the legacy text, never rewritten");
            $this->assertSame($skippedBefore[$name]->amount, $row->amount, "{$name}: amount must be untouched");
            $this->assertFalse(MealPlan::where('name_ru', $name)->exists(), "{$name} must never get a MealPlan");
        }
    }

    // ----- 6. Transport/uniform existing pricing remains untouched --------------

    public function test_transport_and_uniform_fee_prices_are_never_written_to(): void
    {
        $transportBefore = FeePrice::where('fee_id', $this->transportFee->id)->get()->toArray();
        $uniformBefore = FeePrice::where('fee_id', $this->uniformFee->id)->get()->toArray();

        Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027', '--apply' => true]);

        $this->assertEquals($transportBefore, FeePrice::where('fee_id', $this->transportFee->id)->get()->toArray());
        $this->assertEquals($uniformBefore, FeePrice::where('fee_id', $this->uniformFee->id)->get()->toArray());
    }

    // ----- 6b. Inactive (e.g. deactivated legacy) FeePrice rows do not produce/reactivate a uniform_products entry (P1 corrective pass) --

    public function test_inactive_uniform_fee_price_does_not_produce_a_catalog_row(): void
    {
        // An inactive combination — e.g. exactly what SchoolPriceListImportService's
        // legacy-grouped-size deactivation leaves behind for '6–10' once
        // exact-size replacements exist.
        $this->uniformPrice('900.00', 'Толстовка', '6–10')->update(['is_active' => false]);

        Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027', '--apply' => true]);

        $this->assertDatabaseMissing('uniform_products', ['name_ru' => 'Толстовка', 'size' => '6–10']);
        // The active fixtures (Комплект/Майка, both '6–10') still produce their rows.
        $this->assertDatabaseHas('uniform_products', ['name_ru' => 'Комплект', 'size' => '6–10']);
        $this->assertDatabaseHas('uniform_products', ['name_ru' => 'Майка', 'size' => '6–10']);
    }

    // ----- 7. No invoice/payment/cash rows are created ---------------------------

    public function test_no_invoice_payment_or_cash_rows_are_created(): void
    {
        Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027', '--apply' => true]);

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, DB::table('invoice_items')->count());
        $this->assertSame(0, DB::table('invoice_payments')->count());
        $this->assertSame(0, DB::table('cash_transactions')->count());
        $this->assertSame(0, DB::table('student_service_subscriptions')->count());
        $this->assertSame(0, DB::table('students')->count());
    }

    // ----- 8. Generated master data makes readiness READY for the relevant categories --

    public function test_generated_master_data_makes_food_and_uniform_readiness_ready(): void
    {
        Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027', '--apply' => true]);

        $readiness = app(FinanceConfigurationReadinessService::class);
        $this->assertTrue($readiness->forFee($this->foodFee, $this->year)['ready']);
        $this->assertTrue($readiness->forFee($this->uniformFee, $this->year)['ready']);
    }

    public function test_generated_transport_route_and_installment_plan_make_those_categories_ready(): void
    {
        Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027', '--apply' => true]);

        $readiness = app(FinanceConfigurationReadinessService::class);
        $this->assertTrue($readiness->forFee($this->transportFee, $this->year)['ready']);
        $this->assertTrue($readiness->installments()['ready']);
    }

    // ----- 1/2. The 3 a-la-carte names are reported SKIPPED (not blocked) and
    //            never prevent the rest of --apply from completing -------------

    public function test_the_three_a_la_carte_names_are_reported_skipped_not_blocked(): void
    {
        $exitCode = Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SKIPPED — LEGACY A-LA-CARTE / OUTSIDE CURRENT QUICK REGISTRATION MEALPLAN MODEL', $output);
        $this->assertStringNotContainsString('BLOCKED', $output);
        foreach (['Суп', 'Второе блюдо', 'Напиток'] as $name) {
            $this->assertStringContainsString($name, $output);
        }
    }

    public function test_the_three_a_la_carte_names_do_not_block_the_rest_of_apply(): void
    {
        $exitCode = Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027', '--apply' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Apply complete.', $output);
        $this->assertFalse(MealPlan::where('name_ru', 'Суп')->exists());
        $this->assertFalse(MealPlan::where('name_ru', 'Второе блюдо')->exists());
        $this->assertFalse(MealPlan::where('name_ru', 'Напиток')->exists());

        // Everything else completes fully despite the 3 skipped rows.
        $this->assertTrue(MealPlan::where('name_ru', 'Обед')->exists());
        $this->assertSame(3, DB::table('transport_routes')->count());
        $this->assertGreaterThan(0, DB::table('uniform_products')->count());
        $this->assertSame(1, PaymentPlan::count());
    }

    // ----- Preview content sanity ------------------------------------------------

    public function test_dry_run_preview_shows_every_planned_create_and_the_rollback_section(): void
    {
        $exitCode = Artisan::call('finance:uat-master-data-repair', ['--year' => '2026/2027']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('UAT — Зона 1', $output);
        $this->assertStringContainsString('UAT — 2 платежа 50/50', $output);
        $this->assertStringContainsString('Комплект', $output);
        $this->assertStringContainsString('H. Rollback / reversal information', $output);
        $this->assertStringContainsString('UPDATE fee_prices SET option_value', $output);
    }
}
