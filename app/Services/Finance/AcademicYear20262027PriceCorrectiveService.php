<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeeBillingPeriod;
use App\Models\FeePrice;
use App\Models\MealPlan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AcademicYear20262027PriceCorrectiveService
{
    public const TARGET_YEAR_NAME = '2026/2027';

    public const AFTER_SCHOOL_NAME = 'ГРУППА ПРОДЛЕННОГО ДНЯ';

    public const REASON = 'Утвержденный прайс-лист 2026/2027';

    private const TUITION = [
        'Подготовительный класс' => ['yearly' => '40500.00', 'monthly' => '4500.00'],
        '1–4 классы' => ['yearly' => '49500.00', 'monthly' => '5500.00'],
        '5–6 классы' => ['yearly' => '58500.00', 'monthly' => '6500.00'],
        '7–8 классы' => ['yearly' => '67500.00', 'monthly' => '7500.00'],
        '9–11 классы' => ['yearly' => '81000.00', 'monthly' => '9000.00'],
    ];

    private const TRANSPORT = [
        'Каусер, Мубарак 2, Интерконтиненталь' => ['yearly' => '16000.00', 'monthly' => '2000.00'],
        'Арабия, Мадарес, Шератон' => ['yearly' => '17600.00', 'monthly' => '2200.00'],
        'Мубарак 7, Эль-Хеляль, Эль-Ахья' => ['yearly' => '20000.00', 'monthly' => '2500.00'],
    ];

    private const FOOD = [
        'Комплексное питание' => '250.00', 'Завтрак' => '100.00', 'Обед' => '150.00',
        'Суп' => '50.00', 'Второе блюдо' => '100.00', 'Напиток' => '10.00',
    ];

    private const UNIFORM_TIERS = [
        'small' => ['sizes' => ['6', '8', '10'], 'prices' => ['Комплект' => '2400.00', 'Майка' => '500.00', 'Поло' => '700.00', 'Толстовка' => '950.00']],
        'medium' => ['sizes' => ['12', '14', '16'], 'prices' => ['Комплект' => '3000.00', 'Майка' => '550.00', 'Поло' => '800.00', 'Толстовка' => '1300.00']],
        'large' => ['sizes' => ['S', 'M', 'L', 'XL'], 'prices' => ['Комплект' => '3600.00', 'Майка' => '600.00', 'Поло' => '900.00', 'Толстовка' => '1600.00']],
    ];

    public function __construct(private UniformProductCatalogSyncService $uniformSync) {}

    public function run(int $yearId, bool $apply = false): array
    {
        DB::beginTransaction();
        try {
            $year = $this->resolveYearOrFail($yearId);

            $summary = ['year_id' => $year->id, 'changes' => [], 'apply' => $apply];
            $this->registration($year, $summary, $apply);
            $this->updateTuition($year, $summary, $apply);
            $this->updateTransport($year, $summary, $apply);
            $this->updateFood($year, $summary, $apply);
            $this->updateUniform($year, $summary, $apply);
            $this->afterSchool($year, $summary, $apply);

            $apply ? DB::commit() : DB::rollBack();

            return $summary;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Food-only execution path (Option B — see the discovery that led to
     * this method). Registration/Tuition/Transport/Uniform/After-School
     * each currently have their own, independent, unrelated UAT
     * incompatibilities (stale hardcoded Fee ids, duplicate active
     * tariffs, an option_value format mismatch, an unapproved Fee-creation
     * proposal) — none of that is fixed here, and none of it is reachable
     * from this method. This has its own transaction boundary and calls
     * ONLY updateFood() — it never calls run() and never touches
     * registration()/updateTuition()/updateTransport()/updateUniform()/
     * afterSchool(), so it cannot create, update, or delete anything
     * outside the six canonical Food FeePrice rows and their MealPlan
     * display-price sync, no matter how broken those other categories'
     * data is.
     *
     * @return array{year_id: int, changes: array, apply: bool, food_report: array<int, array<string, mixed>>}
     */
    public function runFoodOnly(int $yearId, bool $apply = false): array
    {
        DB::beginTransaction();
        try {
            $year = $this->resolveYearOrFail($yearId);
            $mealPlanPricesBefore = MealPlan::whereIn('name_ru', array_keys(self::FOOD))->pluck('price', 'name_ru');

            $summary = ['year_id' => $year->id, 'changes' => [], 'apply' => $apply, 'food_report' => []];
            $this->updateFood($year, $summary, $apply);

            // Reporting-only enrichment — never influences any validation
            // or write decision, which already happened inside
            // updateFood() above. amount_after is already the exact target
            // MealPlan.price updateFood() itself would sync to (same
            // self::FOOD value, same loop pass), so it's reused rather
            // than re-derived, to avoid a second copy of the price map.
            foreach ($summary['food_report'] as &$entry) {
                $plan = MealPlan::where('name_ru', $entry['name'])->sole();
                $entry['meal_plan_id'] = $plan->id;
                $entry['meal_plan_price_before'] = (string) ($mealPlanPricesBefore[$entry['name']] ?? $plan->price);
                $entry['meal_plan_price_after'] = $entry['amount_after'];
            }
            unset($entry);

            $apply ? DB::commit() : DB::rollBack();

            return $summary;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function resolveYearOrFail(int $yearId): AcademicYear
    {
        $year = AcademicYear::query()->lockForUpdate()->find($yearId);
        if (! $year || AcademicYear::normalizeName($year->name) !== AcademicYear::normalizeName(self::TARGET_YEAR_NAME)) {
            throw new RuntimeException("AcademicYear id={$yearId} must exist and be named ".self::TARGET_YEAR_NAME.'.');
        }

        return $year;
    }

    private function registration(AcademicYear $year, array &$summary, bool $apply): void
    {
        $canonical = Fee::query()->lockForUpdate()->find(1);
        $duplicate = Fee::query()->lockForUpdate()->find(8);
        if (! $canonical || $canonical->category !== Fee::CATEGORY_REGISTRATION || ! $duplicate || $duplicate->category !== Fee::CATEGORY_REGISTRATION) {
            throw new RuntimeException('Expected registration Fees #1 and #8 were not found with registration category.');
        }
        $prices = FeePrice::where('fee_id', 1)->where('academic_year_id', $year->id)->get();
        if (! $prices->contains(fn ($p) => bccomp((string) $p->amount, '8000.00', 2) === 0)) {
            throw new RuntimeException('Canonical registration Fee #1 has no AY tariff of 8000.00.');
        }
        $this->change($summary, 'fee#8.is_active', $duplicate->is_active ? 'true' : 'false', 'false');
        if ($apply && $duplicate->is_active) {
            $duplicate->update(['is_active' => false]);
        }
    }

    private function updateTuition(AcademicYear $year, array &$summary, bool $apply): void
    {
        $expected = [];
        foreach (self::TUITION as $group => $periods) {
            foreach ($periods as $period => $amount) {
                $expected[$group.'|'.$period] = $amount;
            }
        }
        $fees = Fee::where('category', Fee::CATEGORY_TUITION)->where('is_test_data', false)->lockForUpdate()->get();
        $matches = $fees->filter(function (Fee $fee) use ($year, $expected): bool {
            $keys = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->where('is_active', true)->get()
                ->map(fn (FeePrice $price) => $price->grade_group.'|'.$price->payment_period);

            return collect(array_keys($expected))->diff($keys)->isEmpty();
        });
        if ($matches->count() !== 1) {
            throw new RuntimeException('Expected exactly one Tuition Fee owning the complete AY tariff matrix.');
        }
        $fee = $matches->first();
        $this->updatePriceMatrix($fee, $year, $expected, fn ($p) => $p->grade_group.'|'.$p->payment_period, $summary, $apply, 'tuition');
    }

    private function updateTransport(AcademicYear $year, array &$summary, bool $apply): void
    {
        $fee = Fee::find(10);
        if (! $fee || $fee->category !== Fee::CATEGORY_TRANSPORT) {
            throw new RuntimeException('Expected Transport Fee #10 was not found.');
        }
        $expected = [];
        foreach (self::TRANSPORT as $zone => $periods) {
            foreach ($periods as $period => $amount) {
                $expected[$zone.'|'.$period] = $amount;
            }
        }
        $this->updatePriceMatrix($fee, $year, $expected, function ($p) {
            if ($p->option_type !== SchoolPriceListImportService::TRANSPORT_ZONE_OPTION_TYPE) {
                throw new RuntimeException("Transport FeePrice #{$p->id} option_type is not zone.");
            }

            return $p->option_value.'|'.$p->payment_period;
        }, $summary, $apply, 'transport');
    }

    private function updateFood(AcademicYear $year, array &$summary, bool $apply): void
    {
        $fee = $this->oneOperationalFee(Fee::CATEGORY_FOOD);
        $plans = MealPlan::whereIn('name_ru', array_keys(self::FOOD))->get()->keyBy('id');
        $rows = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->where('is_active', true)->lockForUpdate()->get();
        $mapped = [];
        foreach ($rows as $row) {
            $name = is_numeric($row->option_value)
                ? $plans->get((int) $row->option_value)?->name_ru
                : ($row->option_value ?: $row->item);
            if (! isset(self::FOOD[$name])) {
                // Not one of the six canonical Food items — the pre-existing,
                // unrelated invariant still applies unchanged: any other
                // active Food row for this Fee/year must already be daily.
                if ($row->payment_period !== Fee::PERIOD_DAILY) {
                    throw new RuntimeException("Food FeePrice #{$row->id} is not daily.");
                }

                continue;
            }
            // A canonical Food row's payment_period is only ever tolerated
            // as exactly 'daily' (nothing to do) or NULL (a known UAT
            // master-data gap this corrective is allowed to close). Any
            // other value (e.g. 'weekly', 'monthly') is a genuine conflict
            // this corrective must never silently paper over.
            if (! in_array($row->payment_period, [Fee::PERIOD_DAILY, null], true)) {
                throw new RuntimeException("Food FeePrice #{$row->id} has an unexpected payment_period '{$row->payment_period}' (expected 'daily' or null).");
            }
            if (isset($mapped[$name])) {
                throw new RuntimeException("Duplicate Food tariff: {$name}.");
            }
            $mapped[$name] = $row;
        }
        if (array_diff_key(self::FOOD, $mapped) || count($mapped) !== 6) {
            throw new RuntimeException('The six expected Food tariffs were not found uniquely.');
        }
        foreach (self::FOOD as $name => $amount) {
            $price = $mapped[$name];
            $amountBefore = (string) $price->amount;
            $periodBefore = $price->payment_period;
            $this->setPrice($price, $amount, $summary, $apply, 'food.'.$name);
            $this->setFoodPaymentPeriod($price, $summary, $apply, 'food_payment_period.'.$name);
            $summary['food_report'][] = [
                'fee_price_id' => $price->id,
                'name' => $name,
                'option_value' => $price->option_value,
                'payment_period_before' => $periodBefore ?? 'NULL',
                'payment_period_after' => Fee::PERIOD_DAILY,
                'amount_before' => $amountBefore,
                'amount_after' => $amount,
            ];
        }
        foreach (array_keys(self::FOOD) as $name) {
            $plan = MealPlan::where('name_ru', $name)->lockForUpdate()->sole();
            if ($plan->period !== MealPlan::PERIOD_DAILY) {
                throw new RuntimeException("MealPlan {$name} is not daily.");
            }
            $this->change($summary, 'meal_plan.'.$name, (string) $plan->price, self::FOOD[$name]);
            if ($apply && bccomp((string) $plan->price, self::FOOD[$name], 2) !== 0) {
                $plan->update(['price' => self::FOOD[$name]]);
            }
        }
    }

    private function updateUniform(AcademicYear $year, array &$summary, bool $apply): void
    {
        $fee = Fee::find(13);
        if (! $fee || $fee->category !== Fee::CATEGORY_UNIFORM) {
            throw new RuntimeException('Expected Uniform Fee #13 was not found.');
        }
        $expected = [];
        foreach (self::UNIFORM_TIERS as $tier) {
            foreach ($tier['sizes'] as $size) {
                foreach ($tier['prices'] as $item => $amount) {
                    $expected[$item.'|'.$size] = $amount;
                }
            }
        }
        $this->updatePriceMatrix($fee, $year, $expected, fn ($p) => $p->item.'|'.$p->size, $summary, $apply, 'uniform', ['6', '8', '10', '12', '14', '16', 'S', 'M', 'L', 'XL']);
        $sync = $this->uniformSync->sync($year, $apply);
        if ($sync['missing'] || $sync['ambiguous_source_pairs'] || $sync['duplicate_catalog_pairs']) {
            throw new RuntimeException('Uniform catalog sync failed closed.');
        }
        $summary['uniform_catalog'] = $sync;
    }

    private function afterSchool(AcademicYear $year, array &$summary, bool $apply): void
    {
        $fees = Fee::where('name_ru', self::AFTER_SCHOOL_NAME)->lockForUpdate()->get();
        if ($fees->count() > 1) {
            throw new RuntimeException('Duplicate after-school Fees already exist.');
        }
        $fee = $fees->first();
        if ($fee && ($fee->category !== Fee::CATEGORY_EXTRA_CLASSES || $fee->is_test_data)) {
            throw new RuntimeException('Conflicting after-school Fee identity.');
        }
        if (! $fee) {
            $this->change($summary, 'after_school.fee', 'missing', 'create');
            if ($apply) {
                $fee = Fee::create(['name_ru' => self::AFTER_SCHOOL_NAME, 'category' => Fee::CATEGORY_EXTRA_CLASSES, 'type' => 'monthly', 'payment_period' => Fee::PERIOD_MONTHLY, 'amount' => '0.00', 'is_active' => true, 'is_test_data' => false]);
            }
        }
        if (! $apply && ! $fee) {
            $summary['changes'][] = ['key' => 'after_school.tariff', 'before' => 'missing', 'after' => '2000.00'];

            return;
        }
        if (! $fee->is_active) {
            throw new RuntimeException('Existing after-school Fee is inactive.');
        }
        $fee->billingPeriods()->firstOrCreate(['billing_period' => FeeBillingPeriod::PERIOD_MONTHLY]);
        $prices = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->get();
        if ($prices->count() > 1) {
            throw new RuntimeException('Ambiguous after-school AY tariff.');
        }
        $price = $prices->first();
        if (! $price) {
            $this->change($summary, 'after_school.tariff', 'missing', '2000.00');
            if ($apply) {
                FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $year->id, 'payment_period' => Fee::PERIOD_MONTHLY, 'amount' => '2000.00', 'currency' => 'EGP', 'start_date' => $year->start_date, 'end_date' => $year->end_date, 'is_active' => true, 'change_reason' => self::REASON]);
            }
        } else {
            if (! $price->is_active || $price->payment_period !== Fee::PERIOD_MONTHLY) {
                throw new RuntimeException('Existing after-school AY tariff is not an active monthly tariff.');
            }
            $this->setPrice($price, '2000.00', $summary, $apply, 'after_school.tariff');
        }
    }

    private function oneOperationalFee(string $category): Fee
    {
        $fees = Fee::where('category', $category)->where('is_test_data', false)->lockForUpdate()->get();
        if ($fees->count() !== 1) {
            throw new RuntimeException("Expected exactly one operational {$category} Fee.");
        }

        return $fees->first();
    }

    private function updatePriceMatrix(Fee $fee, AcademicYear $year, array $expected, callable $key, array &$summary, bool $apply, string $prefix, ?array $sizes = null): void
    {
        $q = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)->where('is_active', true);
        if ($sizes) {
            $q->whereIn('size', $sizes);
        }
        $rows = $q->lockForUpdate()->get();
        $mapped = [];
        foreach ($rows as $row) {
            $k = $key($row);
            if (isset($expected[$k])) {
                if (isset($mapped[$k])) {
                    throw new RuntimeException("Duplicate {$prefix} tariff {$k}.");
                }
                $mapped[$k] = $row;
            }
        }
        if (array_diff_key($expected, $mapped) || count($mapped) !== count($expected)) {
            throw new RuntimeException("Expected {$prefix} tariff matrix is incomplete.");
        }
        foreach ($expected as $k => $amount) {
            $this->setPrice($mapped[$k], $amount, $summary, $apply, $prefix.'.'.$k);
        }
    }

    /**
     * The only Food-specific mutation beyond amount: a NULL payment_period
     * on one of the six canonical rows (a known UAT master-data gap) is
     * closed to 'daily'. Non-canonical rows and any already-'daily' row
     * are never touched — validated as such before this is ever called
     * (see updateFood()). Never sets change_reason: unlike an amount
     * correction, this is an identity-shape repair, not a price change.
     */
    private function setFoodPaymentPeriod(FeePrice $price, array &$summary, bool $apply, string $key): void
    {
        $before = $price->payment_period ?? 'NULL';
        $this->change($summary, $key, $before, Fee::PERIOD_DAILY);
        if ($apply && $price->payment_period !== Fee::PERIOD_DAILY) {
            $price->update(['payment_period' => Fee::PERIOD_DAILY]);
        }
    }

    private function setPrice(FeePrice $price, string $amount, array &$summary, bool $apply, string $key): void
    {
        $before = (string) $price->amount;
        $this->change($summary, $key, $before, $amount);
        if ($apply && bccomp($before, $amount, 2) !== 0) {
            $price->update(['amount' => $amount, 'change_reason' => self::REASON]);
        }
    }

    private function change(array &$summary, string $key, string $before, string $after): void
    {
        if ($before !== $after && ! (is_numeric($before) && is_numeric($after) && bccomp($before, $after, 2) === 0)) {
            $summary['changes'][] = compact('key', 'before', 'after');
        }
    }
}
