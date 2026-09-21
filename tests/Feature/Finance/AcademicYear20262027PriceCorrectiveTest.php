<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\MealPlan;
use App\Services\Finance\AcademicYear20262027PriceCorrectiveService;
use App\Services\Finance\SchoolPriceListImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AcademicYear20262027PriceCorrectiveTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    private AcademicYear $otherYear;

    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->otherYear = AcademicYear::create(['name' => '2027/2028', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'is_active' => false]);
        $this->seedAuthoritativeShape();
    }

    public function test_dry_run_has_zero_writes_and_reports_changes(): void
    {
        $before = $this->databaseState();
        $result = app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, false);
        $this->assertNotEmpty($result['changes']);
        $this->assertSame($before, $this->databaseState());
        $this->assertDatabaseMissing('fees', ['name_ru' => AcademicYear20262027PriceCorrectiveService::AFTER_SCHOOL_NAME]);
    }

    public function test_apply_updates_complete_catalog_preserves_history_and_is_idempotent(): void
    {
        $legacyUniform = FeePrice::where('fee_id', 13)->where('size', '6–10')->firstOrFail()->only(['id', 'amount', 'is_active', 'change_reason']);
        $otherYear = FeePrice::where('academic_year_id', $this->otherYear->id)->firstOrFail()->only(['id', 'amount', 'change_reason']);
        $invoiceCount = Invoice::count();
        $paymentCount = InvoicePayment::count();

        $first = app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, true);
        $this->assertNotEmpty($first['changes']);

        $this->assertSame('8000.00', FeePrice::where('fee_id', 1)->where('academic_year_id', $this->year->id)->sole()->amount);
        $this->assertTrue(Fee::findOrFail(1)->is_active);
        $this->assertFalse(Fee::findOrFail(8)->is_active);
        $this->assertDatabaseHas('fees', ['id' => 8, 'name_ru' => 'Организационный взнос']);

        $tuition = [
            'Подготовительный класс' => ['yearly' => '40500.00', 'monthly' => '4500.00'], '1–4 классы' => ['yearly' => '49500.00', 'monthly' => '5500.00'],
            '5–6 классы' => ['yearly' => '58500.00', 'monthly' => '6500.00'], '7–8 классы' => ['yearly' => '67500.00', 'monthly' => '7500.00'],
            '9–11 классы' => ['yearly' => '81000.00', 'monthly' => '9000.00'],
        ];
        foreach ($tuition as $group => $periods) {
            foreach ($periods as $period => $amount) {
                $this->assertSame($amount, FeePrice::where('fee_id', 2)->where('academic_year_id', $this->year->id)->where('grade_group', $group)->where('payment_period', $period)->sole()->amount);
            }
        }

        $transport = [
            'Каусер, Мубарак 2, Интерконтиненталь' => ['yearly' => '16000.00', 'monthly' => '2000.00'],
            'Арабия, Мадарес, Шератон' => ['yearly' => '17600.00', 'monthly' => '2200.00'],
            'Мубарак 7, Эль-Хеляль, Эль-Ахья' => ['yearly' => '20000.00', 'monthly' => '2500.00'],
        ];
        foreach ($transport as $zone => $periods) {
            foreach ($periods as $period => $amount) {
                $row = FeePrice::where('fee_id', 10)->where('academic_year_id', $this->year->id)->where('option_value', $zone)->where('payment_period', $period)->sole();
                $this->assertSame($amount, $row->amount);
                $this->assertSame('zone', $row->option_type);
                $this->assertSame($zone, $row->option_value);
            }
        }

        $food = ['Комплексное питание' => '250.00', 'Завтрак' => '100.00', 'Обед' => '150.00', 'Суп' => '50.00', 'Второе блюдо' => '100.00', 'Напиток' => '10.00'];
        $plans = MealPlan::pluck('name_ru', 'id');
        foreach (FeePrice::where('fee_id', 11)->where('academic_year_id', $this->year->id)->get() as $row) {
            $name = is_numeric($row->option_value) ? $plans[(int) $row->option_value] : ($row->option_value ?: $row->item);
            $this->assertSame($food[$name], $row->amount);
            $this->assertSame('daily', $row->payment_period);
        }
        foreach ($food as $name => $amount) {
            $plan = MealPlan::where('name_ru', $name)->sole();
            $this->assertSame($amount, $plan->price);
            $this->assertSame('daily', $plan->period);
        }

        $this->assertSame(40, FeePrice::where('fee_id', 13)->where('academic_year_id', $this->year->id)->whereIn('size', ['6', '8', '10', '12', '14', '16', 'S', 'M', 'L', 'XL'])->count());
        foreach ($this->uniformExpected() as $key => $amount) {
            [$item,$size] = explode('|', $key);
            $price = FeePrice::where('fee_id', 13)->where('academic_year_id', $this->year->id)->where('item', $item)->where('size', $size)->sole();
            $this->assertSame($amount, $price->amount);
            $this->assertEquals($amount, DB::table('uniform_products')->where('name_ru', $item)->where('size', $size)->value('price'));
        }
        $this->assertSame($legacyUniform, FeePrice::findOrFail($legacyUniform['id'])->only(['id', 'amount', 'is_active', 'change_reason']));

        $after = Fee::where('name_ru', AcademicYear20262027PriceCorrectiveService::AFTER_SCHOOL_NAME)->sole();
        $this->assertSame(Fee::CATEGORY_EXTRA_CLASSES, $after->category);
        $this->assertTrue($after->is_active);
        $this->assertDatabaseHas('fee_billing_periods', ['fee_id' => $after->id, 'billing_period' => 'monthly']);
        $this->assertDatabaseHas('fee_prices', ['fee_id' => $after->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'amount' => 2000]);
        $this->assertSame($otherYear, FeePrice::findOrFail($otherYear['id'])->only(['id', 'amount', 'change_reason']));
        $this->assertSame($invoiceCount, Invoice::count());
        $this->assertSame($paymentCount, InvoicePayment::count());

        $state = $this->databaseState();
        $second = app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, true);
        $this->assertSame([], $second['changes']);
        $this->assertSame($state, $this->databaseState());
        $this->assertSame(1, Fee::where('name_ru', AcademicYear20262027PriceCorrectiveService::AFTER_SCHOOL_NAME)->count());
    }

    public function test_wrong_year_fails_closed_and_mid_apply_failure_rolls_back(): void
    {
        $before = $this->databaseState();
        try {
            app(AcademicYear20262027PriceCorrectiveService::class)->run($this->otherYear->id, true);
            $this->fail('Expected failure');
        } catch (RuntimeException) {
        }
        $this->assertSame($before, $this->databaseState());

        $throw = true;
        FeePrice::updating(function (FeePrice $price) use (&$throw) {
            if ($throw && $price->fee_id === 10) {
                $throw = false;
                throw new RuntimeException('injected');
            }
        });
        try {
            app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, true);
            $this->fail('Expected injected failure');
        } catch (RuntimeException $e) {
            $this->assertSame('injected', $e->getMessage());
        }
        $this->assertSame($before, $this->databaseState());
    }

    public function test_legacy_import_reuses_active_canonical_registration_fee_despite_name_difference(): void
    {
        app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, true);
        AcademicYear::create(['name' => SchoolPriceListImportService::YEAR, 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_active' => false]);
        app(SchoolPriceListImportService::class)->import();
        $this->assertSame(2, Fee::where('category', Fee::CATEGORY_REGISTRATION)->count());
        $this->assertDatabaseHas('fee_prices', ['fee_id' => 1, 'amount' => 7000, 'payment_period' => 'yearly']);
        $this->assertFalse(Fee::findOrFail(8)->is_active);
    }

    // ===== A/B: AcademicYear whitespace-insensitive identity match =========

    public function test_a_spaced_academic_year_name_is_still_recognized_as_2026_2027(): void
    {
        $this->year->update(['name' => '2026 / 2027']);

        $result = app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, false);

        $this->assertNotEmpty($result['changes']);
    }

    public function test_a_spaced_but_genuinely_different_year_is_still_rejected(): void
    {
        $this->otherYear->update(['name' => '2027 / 2028']);

        $this->expectException(RuntimeException::class);
        app(AcademicYear20262027PriceCorrectiveService::class)->run($this->otherYear->id, false);
    }

    // ===== C-M: Food payment_period NULL -> daily corrective ===============

    /** Reshapes the 3 legacy Food rows to mirror the real verified UAT shape: NULL payment_period, option_value textual or numeric. */
    private function reshapeLegacyFoodRows(bool $numericOptionValue): array
    {
        $rows = [];
        foreach (['Суп', 'Второе блюдо', 'Напиток'] as $name) {
            $price = FeePrice::where('fee_id', 11)->where('item', $name)->sole();
            $optionValue = $numericOptionValue ? (string) MealPlan::where('name_ru', $name)->sole()->id : $name;
            $price->update(['payment_period' => null, 'option_value' => $optionValue]);
            $rows[$name] = $price->fresh();
        }

        return $rows;
    }

    public function test_dry_run_proposes_daily_and_target_amount_for_textual_option_value_legacy_rows_with_zero_writes(): void
    {
        $rows = $this->reshapeLegacyFoodRows(numericOptionValue: false);
        $before = $this->databaseState();

        $result = app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, false);

        $this->assertSame($before, $this->databaseState(), 'dry-run must cause zero writes');
        $report = collect($result['food_report'])->keyBy('name');
        foreach (['Суп' => '50.00', 'Второе блюдо' => '100.00', 'Напиток' => '10.00'] as $name => $target) {
            $entry = $report[$name];
            $this->assertSame($rows[$name]->id, $entry['fee_price_id']);
            $this->assertSame('NULL', $entry['payment_period_before']);
            $this->assertSame('daily', $entry['payment_period_after']);
            $this->assertSame($rows[$name]->amount, $entry['amount_before']);
            $this->assertSame($target, $entry['amount_after']);
        }
    }

    public function test_apply_sets_daily_and_target_amount_for_textual_option_value_legacy_rows(): void
    {
        $rows = $this->reshapeLegacyFoodRows(numericOptionValue: false);

        app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, true);

        foreach (['Суп' => '50.00', 'Второе блюдо' => '100.00', 'Напиток' => '10.00'] as $name => $target) {
            $row = FeePrice::findOrFail($rows[$name]->id);
            $this->assertSame('daily', $row->payment_period);
            $this->assertSame($target, $row->amount);
            $this->assertSame($name, $row->option_value, 'option_value must remain untouched — Phase 4B, not this corrective, owns it');
        }
    }

    public function test_numeric_meal_plan_option_value_resolves_and_corrects_identically(): void
    {
        $rows = $this->reshapeLegacyFoodRows(numericOptionValue: true);

        app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, true);

        foreach (['Суп' => '50.00', 'Второе блюдо' => '100.00', 'Напиток' => '10.00'] as $name => $target) {
            $row = FeePrice::findOrFail($rows[$name]->id);
            $this->assertSame('daily', $row->payment_period);
            $this->assertSame($target, $row->amount);
            $this->assertTrue(is_numeric($row->option_value), 'option_value must remain numeric — untouched by this corrective');
        }
    }

    public function test_already_daily_rows_are_reported_and_left_unchanged(): void
    {
        // Default fixture shape: all six already 'daily'.
        $result = app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, false);

        $periodChanges = collect($result['changes'])->filter(fn ($c) => str_starts_with($c['key'], 'food_payment_period.'));
        $this->assertCount(0, $periodChanges, 'no payment_period change should be proposed when every row is already daily');
    }

    public function test_unexpected_non_daily_non_null_payment_period_aborts(): void
    {
        FeePrice::where('fee_id', 11)->where('item', 'Суп')->update(['payment_period' => 'weekly']);
        $before = $this->databaseState();

        try {
            app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, true);
            $this->fail('Expected a RuntimeException for the unexpected payment_period.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('unexpected payment_period', $e->getMessage());
        }
        $this->assertSame($before, $this->databaseState(), 'a conflicting payment_period must abort with zero writes');
    }

    public function test_duplicate_canonical_food_candidate_aborts(): void
    {
        $food = Fee::findOrFail(11);
        $this->price($food, $this->year, ['amount' => '99.00', 'payment_period' => null, 'item' => 'Суп']);
        $before = $this->databaseState();

        try {
            app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, true);
            $this->fail('Expected a RuntimeException for the duplicate candidate.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Duplicate Food tariff', $e->getMessage());
        }
        $this->assertSame($before, $this->databaseState(), 'an ambiguous duplicate must abort with zero writes');
    }

    public function test_missing_canonical_food_candidate_aborts(): void
    {
        FeePrice::where('fee_id', 11)->where('item', 'Напиток')->delete();
        $before = $this->databaseState();

        try {
            app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, true);
            $this->fail('Expected a RuntimeException for the missing candidate.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not found uniquely', $e->getMessage());
        }
        $this->assertSame($before, $this->databaseState(), 'a missing canonical item must abort with zero writes');
    }

    public function test_a_food_validation_failure_leaves_no_partial_food_writes_among_the_other_five(): void
    {
        $rows = $this->reshapeLegacyFoodRows(numericOptionValue: false);
        // Суп is now valid (NULL, correctable); poison a different, otherwise-valid row.
        FeePrice::where('fee_id', 11)->where('item', 'Второе блюдо')->update(['payment_period' => 'monthly']);
        $before = $this->databaseState();

        try {
            app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, true);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
        }

        $this->assertSame($before, $this->databaseState(), 'no Food row may be partially written when any one of the six fails validation');
        $this->assertSame('NULL', FeePrice::findOrFail($rows['Суп']->id)->payment_period ?? 'NULL');
    }

    public function test_a_second_apply_after_correcting_null_payment_period_is_idempotent(): void
    {
        $this->reshapeLegacyFoodRows(numericOptionValue: false);
        app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, true);

        $state = $this->databaseState();
        $second = app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, true);

        $this->assertSame([], $second['changes']);
        $this->assertSame($state, $this->databaseState());
    }

    public function test_unrelated_fee_price_rows_remain_unchanged_by_the_null_payment_period_correction(): void
    {
        $this->reshapeLegacyFoodRows(numericOptionValue: false);
        // Genuinely out of this run's scope: a different academic year, and
        // a non-canonical Food row that happens to also have a NULL
        // payment_period — the correction must never touch either.
        $otherYearFood = $this->price(Fee::findOrFail(11), $this->otherYear, ['amount' => '5.00', 'payment_period' => null, 'item' => 'Совсем другое блюдо']);
        $strayFood = $this->price(Fee::findOrFail(11), $this->year, ['amount' => '9.00', 'payment_period' => 'daily', 'item' => 'Совсем другое блюдо']);
        $otherYearBefore = $otherYearFood->only(['id', 'amount', 'payment_period', 'change_reason']);
        $strayBefore = $strayFood->only(['id', 'amount', 'payment_period', 'change_reason']);

        app(AcademicYear20262027PriceCorrectiveService::class)->run($this->year->id, true);

        $this->assertSame($otherYearBefore, FeePrice::findOrFail($otherYearFood->id)->only(['id', 'amount', 'payment_period', 'change_reason']));
        $this->assertSame($strayBefore, FeePrice::findOrFail($strayFood->id)->only(['id', 'amount', 'payment_period', 'change_reason']));
    }

    private function seedAuthoritativeShape(): void
    {
        $registration = $this->fee(1, 'Регистрационный взнос', Fee::CATEGORY_REGISTRATION, 'once', true);
        $this->fee(8, 'Организационный взнос', Fee::CATEGORY_REGISTRATION, 'once', true);
        $tuition = $this->fee(2, 'Обучение', Fee::CATEGORY_TUITION, null, true);
        $transport = $this->fee(10, 'Трансфер', Fee::CATEGORY_TRANSPORT, null, true);
        $food = $this->fee(11, 'Питание', Fee::CATEGORY_FOOD, 'daily', true);
        $uniform = $this->fee(13, 'Школьная форма', Fee::CATEGORY_UNIFORM, 'package', true);
        $this->price($registration, $this->year, ['amount' => '8000.00', 'payment_period' => 'yearly']);
        foreach (['Подготовительный класс', '1–4 классы', '5–6 классы', '7–8 классы', '9–11 классы'] as $group) {
            foreach (['yearly' => '1.00', 'monthly' => '2.00'] as $period => $amount) {
                $this->price($tuition, $this->year, compact('group', 'period', 'amount'), ['grade_group' => $group, 'payment_period' => $period]);
            }
        }
        foreach (['Каусер, Мубарак 2, Интерконтиненталь', 'Арабия, Мадарес, Шератон', 'Мубарак 7, Эль-Хеляль, Эль-Ахья'] as $zone) {
            foreach (['yearly', 'monthly'] as $period) {
                $this->price($transport, $this->year, ['amount' => '3.00', 'payment_period' => $period, 'option_type' => 'zone', 'option_value' => $zone]);
            }
        }
        foreach ([
            ['Комплексное питание', 'both', '170.00'],
            ['Завтрак', 'breakfast', '70.00'],
            ['Обед', 'lunch', '100.00'],
            ['Суп', 'lunch', '20.00'],
            ['Второе блюдо', 'lunch', '80.00'],
            ['Напиток', 'both', '10.00'],
        ] as [$name,$type,$amount]) {
            $plan = MealPlan::create(['name_ru' => $name, 'meal_type' => $type, 'period' => 'daily', 'price' => $amount, 'is_active' => true]);
            $this->price($food, $this->year, ['amount' => $amount, 'payment_period' => 'daily', 'item' => $name]);
        }
        foreach ($this->uniformExpected() as $key => $ignored) {
            [$item,$size] = explode('|', $key);
            $this->price($uniform, $this->year, ['amount' => '4.00', 'payment_period' => 'once', 'item' => $item, 'size' => $size]);
            DB::table('uniform_products')->insert(['name_ru' => $item, 'category' => 'uniform', 'size' => $size, 'price' => '4.00', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->price($uniform, $this->year, ['amount' => '999.00', 'payment_period' => 'once', 'item' => 'Комплект', 'size' => '6–10', 'is_active' => false, 'change_reason' => 'historical']);
        $this->price($tuition, $this->otherYear, ['amount' => '777.00', 'grade_group' => '1–4 классы', 'payment_period' => 'yearly', 'change_reason' => 'other year']);
    }

    private function fee(int $id, string $name, string $category, ?string $period, bool $active): Fee
    {
        $fee = new Fee(['name_ru' => $name, 'category' => $category, 'type' => 'service', 'payment_period' => $period, 'amount' => '0.00', 'is_active' => $active, 'is_test_data' => false]);
        $fee->id = $id;
        $fee->save();

        return $fee;
    }

    private function price(Fee $fee, AcademicYear $year, array $attributes, array $extra = []): FeePrice
    {
        return FeePrice::create(array_merge(['fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '1.00', 'currency' => 'EGP', 'start_date' => $year->start_date, 'end_date' => $year->end_date, 'is_active' => true], $attributes, $extra));
    }

    private function uniformExpected(): array
    {
        $out = [];
        foreach ([[['6', '8', '10'], ['Комплект' => '2400.00', 'Майка' => '500.00', 'Поло' => '700.00', 'Толстовка' => '950.00']], [['12', '14', '16'], ['Комплект' => '3000.00', 'Майка' => '550.00', 'Поло' => '800.00', 'Толстовка' => '1300.00']], [['S', 'M', 'L', 'XL'], ['Комплект' => '3600.00', 'Майка' => '600.00', 'Поло' => '900.00', 'Толстовка' => '1600.00']]] as [$sizes,$prices]) {
            foreach ($sizes as $size) {
                foreach ($prices as $item => $amount) {
                    $out[$item.'|'.$size] = $amount;
                }
            }
        }

        return $out;
    }

    private function databaseState(): array
    {
        return ['fees' => DB::table('fees')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'prices' => DB::table('fee_prices')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'plans' => DB::table('meal_plans')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'uniform' => DB::table('uniform_products')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'periods' => DB::table('fee_billing_periods')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()];
    }
}
