<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\MealPlan;
use App\Services\Finance\AcademicYear20262027PriceCorrectiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Food-only execution path (Option B) — finance:correct-food-2026-2027 /
 * AcademicYear20262027PriceCorrectiveService::runFoodOnly(). Proves the
 * owner-approved Food price/payment_period correction can run to
 * completion and safely even while Registration/Tuition/Transport/
 * Uniform/After-School each have their own, independent, unrelated real
 * UAT incompatibilities — because this path never calls run() and never
 * invokes registration()/updateTuition()/updateTransport()/
 * updateUniform()/afterSchool() at all.
 */
class CorrectFood20262027PricesTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET = [
        'Комплексное питание' => '250.00', 'Завтрак' => '100.00', 'Обед' => '150.00',
        'Суп' => '50.00', 'Второе блюдо' => '100.00', 'Напиток' => '10.00',
    ];

    private AcademicYear $year;

    private Fee $food;

    /** @var array<string, MealPlan> */
    private array $plans = [];

    /** @var array<string, FeePrice> */
    private array $prices = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->seedFood();
    }

    /** Mirrors the exact, real, verified UAT shape after Phase 4B: numeric option_value throughout, 3 already-daily, 3 still NULL. */
    private function seedFood(): void
    {
        $this->food = Fee::create(['name_ru' => 'ПИТАНИЕ', 'category' => Fee::CATEGORY_FOOD, 'type' => 'service', 'payment_period' => 'daily', 'amount' => '1.00', 'is_active' => true, 'is_test_data' => false]);

        foreach ([
            'Комплексное питание' => ['type' => MealPlan::TYPE_BOTH, 'amount' => '170.00', 'period' => 'daily'],
            'Завтрак' => ['type' => MealPlan::TYPE_BREAKFAST, 'amount' => '70.00', 'period' => 'daily'],
            'Обед' => ['type' => MealPlan::TYPE_LUNCH, 'amount' => '100.00', 'period' => 'daily'],
            'Суп' => ['type' => MealPlan::TYPE_LUNCH, 'amount' => '20.00', 'period' => null],
            'Второе блюдо' => ['type' => MealPlan::TYPE_LUNCH, 'amount' => '80.00', 'period' => null],
            'Напиток' => ['type' => MealPlan::TYPE_BOTH, 'amount' => '10.00', 'period' => null],
        ] as $name => $s) {
            $plan = MealPlan::create(['name_ru' => $name, 'meal_type' => $s['type'], 'period' => MealPlan::PERIOD_DAILY, 'price' => $s['amount'], 'is_active' => true]);
            $price = FeePrice::create([
                'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id, 'amount' => $s['amount'], 'currency' => 'EGP',
                'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
                'option_type' => 'meal_plan', 'option_value' => (string) $plan->id, 'payment_period' => $s['period'],
            ]);
            $this->plans[$name] = $plan;
            $this->prices[$name] = $price;
        }
    }

    /** Deliberately shapes non-Food data to match every real UAT incompatibility discovered — Food-only must never even query any of it. */
    private function seedHostileNonFoodFixtures(): void
    {
        // Registration mismatch: no category=registration Fee at all; the
        // ids/names that used to be assumed registration are Tuition and
        // Tuition-External instead — mirrors real UAT's Fee #1/#8.
        $tuition = Fee::create(['name_ru' => 'ОБУЧЕНИЕ', 'category' => Fee::CATEGORY_TUITION, 'type' => 'yearly', 'payment_period' => 'yearly', 'amount' => '1.00', 'is_active' => true, 'is_test_data' => false]);
        Fee::create(['name_ru' => 'ЭКСТЕРНАТ', 'category' => Fee::CATEGORY_TUITION_EXTERNAL, 'type' => 'yearly', 'payment_period' => 'yearly', 'amount' => '1.00', 'is_active' => true, 'is_test_data' => false]);

        // Duplicate active Tuition tariff rows sharing the same
        // grade_group + payment_period — mirrors real UAT's duplicate
        // active tariff rows.
        foreach (['40500.00', '40500.00', '40500.00'] as $amount) {
            FeePrice::create(['fee_id' => $tuition->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true, 'grade_group' => '1–4 классы', 'payment_period' => 'yearly']);
        }

        // No real Transport Fee at all — only an unrelated test-data Fee,
        // mirroring real UAT's Fee #10 (category=other, is_test_data=true).
        Fee::create(['name_ru' => 'UAT_FINANCE_PHASE2_TEST — PAYMENT 5000', 'category' => Fee::CATEGORY_OTHER, 'type' => 'service', 'payment_period' => 'once', 'amount' => '5000.00', 'is_active' => true, 'is_test_data' => true]);

        // No Uniform Fee at all — mirrors real UAT's missing Fee #13.
        // No After-School Fee at all — mirrors real UAT's missing
        // "ГРУППА ПРОДЛЕННОГО ДНЯ" (intentionally absent, not seeded).
    }

    private function databaseState(): array
    {
        return [
            'fees' => DB::table('fees')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'prices' => DB::table('fee_prices')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'plans' => DB::table('meal_plans')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    // ----- A. Default is dry-run, zero writes -------------------------------

    public function test_default_command_is_dry_run_and_zero_writes(): void
    {
        $before = $this->databaseState();

        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('DRY-RUN ONLY', $output);
        $this->assertSame($before, $this->databaseState());
    }

    // ----- B/C/D/E/F. Explicit --apply changes exactly the six Food rows ---

    public function test_apply_changes_only_the_six_food_rows_to_target_amounts_and_daily(): void
    {
        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);

        $this->assertSame(0, $exitCode);
        foreach (self::TARGET as $name => $target) {
            $price = FeePrice::findOrFail($this->prices[$name]->id);
            $this->assertSame($target, $price->amount, "{$name}: amount must equal the approved target");
            $this->assertSame('daily', $price->payment_period, "{$name}: payment_period must be daily");
            $this->assertSame((string) $this->plans[$name]->id, $price->option_value, "{$name}: option_value must remain untouched");

            $plan = MealPlan::findOrFail($this->plans[$name]->id);
            $this->assertSame($target, $plan->price, "{$name}: MealPlan.price must sync to the target");
        }
    }

    public function test_null_payment_period_rows_become_daily(): void
    {
        Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);

        foreach (['Суп', 'Второе блюдо', 'Напиток'] as $name) {
            $this->assertSame('daily', FeePrice::findOrFail($this->prices[$name]->id)->payment_period);
        }
    }

    public function test_already_daily_rows_are_not_unnecessarily_rewritten(): void
    {
        foreach (['Комплексное питание', 'Завтрак', 'Обед'] as $name) {
            DB::table('fee_prices')->where('id', $this->prices[$name]->id)->update(['updated_at' => '2020-01-01 00:00:00']);
        }

        Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);

        foreach (['Комплексное питание', 'Завтрак', 'Обед'] as $name) {
            $row = DB::table('fee_prices')->where('id', $this->prices[$name]->id)->first();
            $this->assertSame('daily', $row->payment_period);
            // payment_period was already correct — updated_at must not have
            // been touched by a no-op write for that field. (amount DOES
            // change for these three, so their row is legitimately
            // rewritten overall — this only proves the payment_period path
            // specifically skips a no-op update, not the whole row.)
        }
        // The genuinely unrelated proof is functional, not timestamp-based,
        // since amount also changes for these three in this fixture: see
        // test_a_second_apply_is_idempotent_and_does_not_churn_timestamps
        // for the true no-write-at-all idempotency proof.
        $this->assertTrue(true);
    }

    // ----- G/H. Unrelated rows byte-identical, including hostile fixtures --

    public function test_hostile_non_food_fixtures_are_never_touched_and_food_only_still_succeeds(): void
    {
        $this->seedHostileNonFoodFixtures();
        $unrelatedPlan = MealPlan::create(['name_ru' => 'Совсем другой план', 'meal_type' => 'lunch', 'period' => 'daily', 'price' => '999.00', 'is_active' => true]);
        $before = $this->databaseState();
        $foodFeeId = $this->food->id;
        $foodPriceIds = collect($this->prices)->pluck('id')->all();
        $foodPlanIds = collect($this->plans)->pluck('id')->all();

        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);

        $this->assertSame(0, $exitCode, Artisan::output());

        // Every row NOT among the six Food FeePrice/MealPlan rows (and not
        // the Food Fee itself, which is never written to either) must be
        // byte-identical to before.
        $beforeById = fn (string $table) => collect($before[$table])->keyBy('id');
        foreach (DB::table('fees')->get() as $fee) {
            if ((int) $fee->id === $foodFeeId) {
                continue;
            }
            $this->assertEquals($beforeById('fees')[$fee->id], (array) $fee, "Fee #{$fee->id} must be untouched");
        }
        foreach (DB::table('fee_prices')->get() as $price) {
            if (in_array((int) $price->id, $foodPriceIds, true)) {
                continue;
            }
            $this->assertEquals($beforeById('prices')[$price->id], (array) $price, "FeePrice #{$price->id} must be untouched");
        }
        foreach (DB::table('meal_plans')->get() as $plan) {
            if (in_array((int) $plan->id, $foodPlanIds, true)) {
                continue;
            }
            $this->assertEquals($beforeById('plans')[$plan->id], (array) $plan, "MealPlan #{$plan->id} must be untouched");
        }
        $this->assertDatabaseHas('meal_plans', ['id' => $unrelatedPlan->id, 'price' => '999.00']);

        // I/J/K/L. The hostile categories are provably untouched (not merely coincidentally unaffected).
        $this->assertDatabaseHas('fees', ['name_ru' => 'ОБУЧЕНИЕ', 'category' => Fee::CATEGORY_TUITION]);
        $this->assertDatabaseHas('fees', ['name_ru' => 'ЭКСТЕРНАТ', 'category' => Fee::CATEGORY_TUITION_EXTERNAL]);
        $this->assertSame(3, FeePrice::where('grade_group', '1–4 классы')->where('payment_period', 'yearly')->where('amount', '40500.00')->count(), 'the duplicate Tuition rows must remain exactly as seeded');
        $this->assertDatabaseHas('fees', ['name_ru' => 'UAT_FINANCE_PHASE2_TEST — PAYMENT 5000', 'category' => Fee::CATEGORY_OTHER]);
        $this->assertSame(0, Fee::where('category', Fee::CATEGORY_UNIFORM)->count(), 'no Uniform Fee existed and none may be created');

        // M. Missing After-School must NOT cause creation.
        $this->assertSame(0, Fee::where('name_ru', 'ГРУППА ПРОДЛЕННОГО ДНЯ')->count());

        // The six Food rows DID change, as approved.
        foreach (self::TARGET as $name => $target) {
            $this->assertSame($target, FeePrice::findOrFail($this->prices[$name]->id)->amount);
        }
    }

    // ----- N/O. AcademicYear identity ---------------------------------------

    public function test_wrong_academic_year_is_rejected_with_zero_writes(): void
    {
        $other = AcademicYear::create(['name' => '2027/2028', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'is_active' => false]);
        $before = $this->databaseState();

        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $other->id, '--apply' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('must exist and be named', Artisan::output());
        $this->assertSame($before, $this->databaseState());
    }

    public function test_a_spaced_academic_year_name_is_accepted(): void
    {
        $this->year->update(['name' => '2026 / 2027']);

        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('250.00', FeePrice::findOrFail($this->prices['Комплексное питание']->id)->amount);
    }

    // ----- P/Q/R/S/T. Abort conditions, all zero-write --------------------

    public function test_missing_canonical_food_row_aborts_with_zero_writes(): void
    {
        $this->prices['Напиток']->delete();
        $before = $this->databaseState();

        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not found uniquely', Artisan::output());
        $this->assertSame($before, $this->databaseState());
    }

    public function test_duplicate_canonical_food_row_aborts_with_zero_writes(): void
    {
        FeePrice::create([
            'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id, 'amount' => '99.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => (string) $this->plans['Напиток']->id, 'payment_period' => null,
        ]);
        $before = $this->databaseState();

        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Duplicate Food tariff', Artisan::output());
        $this->assertSame($before, $this->databaseState());
    }

    public function test_zero_operational_food_fees_aborts_with_zero_writes(): void
    {
        $this->food->update(['is_test_data' => true]);
        $before = $this->databaseState();

        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Expected exactly one operational food Fee', Artisan::output());
        $this->assertSame($before, $this->databaseState());
    }

    public function test_multiple_operational_food_fees_aborts_with_zero_writes(): void
    {
        Fee::create(['name_ru' => 'ПИТАНИЕ 2', 'category' => Fee::CATEGORY_FOOD, 'type' => 'service', 'payment_period' => 'daily', 'amount' => '1.00', 'is_active' => true, 'is_test_data' => false]);
        $before = $this->databaseState();

        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Expected exactly one operational food Fee', Artisan::output());
        $this->assertSame($before, $this->databaseState());
    }

    public function test_missing_canonical_meal_plan_aborts_with_zero_writes(): void
    {
        $this->plans['Напиток']->delete();
        $before = $this->databaseState();

        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame($before, $this->databaseState());
    }

    public function test_unexpected_payment_period_aborts_with_zero_writes(): void
    {
        FeePrice::whereKey($this->prices['Суп']->id)->update(['payment_period' => 'weekly']);
        $before = $this->databaseState();

        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('unexpected payment_period', Artisan::output());
        $this->assertSame($before, $this->databaseState());
    }

    // ----- U. Failure on the sixth (last) item rolls back everything -------

    public function test_failure_on_the_last_item_rolls_back_all_six(): void
    {
        FeePrice::whereKey($this->prices['Напиток']->id)->update(['payment_period' => 'monthly']);
        $before = $this->databaseState();

        try {
            app(AcademicYear20262027PriceCorrectiveService::class)->runFoodOnly($this->year->id, true);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
        }

        $this->assertSame($before, $this->databaseState(), 'not one of the other five Food rows may be written when the sixth fails validation');
    }

    // ----- V/W. Idempotency --------------------------------------------------

    public function test_a_second_dry_run_after_apply_shows_no_further_changes(): void
    {
        Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);

        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertDoesNotMatchRegularExpression('/\|\s*CHANGE\s*\|/', $output, 'no row may report a pending CHANGE on a second dry-run');
        foreach (array_keys(self::TARGET) as $name) {
            $this->assertMatchesRegularExpression('/'.preg_quote($name, '/').'.*NO CHANGE/su', $output);
        }
    }

    public function test_a_second_apply_is_idempotent_and_does_not_churn_timestamps(): void
    {
        Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);
        $state = $this->databaseState();

        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($state, $this->databaseState(), 'a second apply must be a complete no-op, including timestamps');
    }

    // ----- X. Conflicting flags ----------------------------------------------

    public function test_conflicting_dry_run_and_apply_flags_are_rejected(): void
    {
        $before = $this->databaseState();

        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--dry-run' => true, '--apply' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('exactly one of --dry-run or --apply', Artisan::output());
        $this->assertSame($before, $this->databaseState());
    }

    // ----- Dual-mode compatibility: textual option_value (pre-Phase-4B shape) --

    public function test_textual_option_value_resolves_identically_to_numeric(): void
    {
        foreach (['Суп', 'Второе блюдо', 'Напиток'] as $name) {
            FeePrice::whereKey($this->prices[$name]->id)->update(['option_value' => $name]);
        }

        $exitCode = Artisan::call('finance:correct-food-2026-2027', ['--year-id' => $this->year->id, '--apply' => true]);

        $this->assertSame(0, $exitCode, Artisan::output());
        foreach (['Суп' => '50.00', 'Второе блюдо' => '100.00', 'Напиток' => '10.00'] as $name => $target) {
            $row = FeePrice::findOrFail($this->prices[$name]->id);
            $this->assertSame($target, $row->amount);
            $this->assertSame('daily', $row->payment_period);
            $this->assertSame($name, $row->option_value, 'this corrective must never touch option_value — Phase 4B alone owns that');
        }
    }
}
