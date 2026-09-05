<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\MealPlan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SchoolPriceListImportService
{
    public const YEAR = '2025/2026';
    public const REASON = 'Первоначальный импорт прайс-листа 2025/2026';

    /**
     * Canonical FeePrice.option_type values. Every producer (this importer,
     * the admin tariff-creation screens) and every consumer (Quick
     * Registration, InvoiceCalculationService) must agree on these two
     * strings — a prior version of this importer used 'Район' for
     * transport, which no consumer ever queried by, silently making every
     * imported transport tariff unresolvable.
     */
    public const TRANSPORT_ZONE_OPTION_TYPE = 'zone';

    public const MEAL_PLAN_OPTION_TYPE = 'meal_plan';

    /** @return array{services_created:int,services_reused:int,tariffs_created:int,tariffs_skipped:int,conflicts:array<int,string>,dry_run:bool} */
    public function import(bool $dryRun = false): array
    {
        DB::beginTransaction();

        try {
            $year = AcademicYear::query()->where('name', self::YEAR)->lockForUpdate()->first();
            if (! $year) {
                throw new RuntimeException('Учебный год 2025/2026 не найден. Сначала создайте учебный год.');
            }

            $result = ['services_created' => 0, 'services_reused' => 0, 'tariffs_created' => 0, 'tariffs_skipped' => 0, 'conflicts' => [], 'dry_run' => $dryRun];

            foreach ($this->catalog() as $definition) {
                $fee = $this->resolveOrCreateFee($definition, $result);
                if (! $fee) {
                    continue;
                }

                $this->processTariffs($fee, $year, $definition['tariffs'], $result);

                // Corrective pass (code review P0) — the exact-size rows
                // above (uniformIndividualSizeVariants()) are this Fee's
                // replacement for the legacy grouped-tier rows
                // (uniformVariants()) for THIS SAME academic year. Once
                // this run has processed the Uniform definition's full
                // tariffs loop, the legacy rows must stop being selectable
                // for NEW sales without ever deleting or rewriting them —
                // but ONLY once the complete exact-size replacement matrix
                // genuinely exists as active (see hardening note below).
                if ($definition['category'] === Fee::CATEGORY_UNIFORM) {
                    $this->deactivateLegacyUniformSizesIfReplacementComplete($fee, $year);
                }
            }

            $dryRun ? DB::rollBack() : DB::commit();

            return $result;
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    /**
     * Year-scoped, single-category import — Uniform only.
     *
     * Reuses the exact same catalog() Uniform definition, the exact same
     * resolveOrCreateFee()/processTariffs()/deactivateLegacyUniformSizes...()
     * logic import() itself uses for that one definition — no price data
     * or business rule is redefined or duplicated here. The only two
     * differences from import(): (1) the target academic year is an
     * explicit, caller-supplied name rather than the hardcoded self::YEAR
     * constant — there is no default, a caller must always name a real,
     * existing year, and a missing year fails closed (throws, creates
     * nothing, rolls back) exactly like import() already does for its own
     * hardcoded year; (2) only the Uniform entry of catalog() is ever
     * touched — Registration/Tuition/Transport/Food/Externat are never
     * looked up, created, or written to by this method under any input.
     *
     * @return array{services_created:int,services_reused:int,tariffs_created:int,tariffs_skipped:int,conflicts:array<int,string>,dry_run:bool}
     */
    public function importUniformOnly(string $academicYearName, bool $dryRun = false): array
    {
        DB::beginTransaction();

        try {
            $year = AcademicYear::query()->where('name', $academicYearName)->lockForUpdate()->first();
            if (! $year) {
                throw new RuntimeException("Учебный год «{$academicYearName}» не найден. Сначала создайте учебный год.");
            }

            $result = ['services_created' => 0, 'services_reused' => 0, 'tariffs_created' => 0, 'tariffs_skipped' => 0, 'conflicts' => [], 'dry_run' => $dryRun];

            $definition = collect($this->catalog())->firstWhere('category', Fee::CATEGORY_UNIFORM);

            $fee = $this->resolveOperationalUniformFee($definition, $result);
            if ($fee) {
                $this->processTariffs($fee, $year, $definition['tariffs'], $result);
                $this->deactivateLegacyUniformSizesIfReplacementComplete($fee, $year);
            }

            $dryRun ? DB::rollBack() : DB::commit();

            return $result;
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    /**
     * Fee lookup-or-create for one catalog() definition, shared by
     * import() and importUniformOnly() — pure extraction of import()'s
     * original inline logic, no behavior change. Returns null (having
     * already recorded the conflict) if a same-named Fee exists under a
     * different category; the caller must skip that definition's tariffs
     * entirely in that case, exactly as import() always has.
     *
     * @param array<string,mixed> $definition
     * @param array{services_created:int,services_reused:int,tariffs_created:int,tariffs_skipped:int,conflicts:array<int,string>,dry_run:bool} $result
     */
    private function resolveOrCreateFee(array $definition, array &$result): ?Fee
    {
        $fee = Fee::query()->where('name_ru', $definition['name'])->lockForUpdate()->first();

        if ($fee && $fee->category !== $definition['category']) {
            $result['conflicts'][] = "Услуга «{$definition['name']}» уже существует в другой категории.";

            return null;
        }

        if ($fee) {
            $result['services_reused']++;

            return $fee;
        }

        $fee = Fee::create([
            'name_ru' => $definition['name'],
            'category' => $definition['category'],
            'type' => $definition['type'],
            'payment_period' => $definition['service_period'],
            'amount' => '0.00',
            'description' => $definition['description'] ?? null,
            'is_active' => true,
        ]);
        $result['services_created']++;

        return $fee;
    }

    /**
     * Uniform-only identity resolution — used ONLY by importUniformOnly(),
     * never by import() (the shared resolveOrCreateFee() above remains
     * the sole resolution path for the other 5 catalog() categories,
     * completely unchanged by this method).
     *
     * The catalog()'s hardcoded Uniform name ('Школьная форма') is an
     * exact, case-sensitive string match under this project's Postgres
     * collation — a real deployed environment's Uniform Fee row may
     * legitimately be stored under different capitalization (e.g.
     * "ШКОЛЬНАЯ ФОРМА") for reasons entirely unrelated to this importer
     * (manual creation, an older import path, etc.). Matching by name
     * alone would then silently create a SECOND, duplicate Uniform Fee
     * every time this method runs — the exact defect this method exists
     * to close. Resolution is instead category-first:
     *
     *   - category = Fee::CATEGORY_UNIFORM
     *   - is_test_data = false (an operator's ad-hoc test fixture must
     *     never be selected as "the" real Uniform service — see
     *     finance:mark-test-fees, the established convention for exactly
     *     this class of exclusion elsewhere in this codebase)
     *
     * is_active is deliberately NOT part of this filter — matching
     * resolveOrCreateFee()'s own existing behavior immediately above,
     * which reuses whatever same-named Fee it finds regardless of its
     * is_active state (line ~141: no is_active check before reuse).
     * There is no reason for the Uniform-only path to be stricter about
     * this than the shared path already is; an inactive-but-real
     * operational Uniform Fee is still the correct one to reuse, not a
     * reason to spawn a second Fee.
     *
     * - Exactly one eligible candidate: reused as-is. name_ru (or any
     *   other identity field) is NEVER written here — only the row's id
     *   is ever used.
     * - Zero eligible candidates: falls through to the existing
     *   name-based resolveOrCreateFee(), preserving current behavior for
     *   a genuinely fresh environment that has no Uniform Fee at all yet
     *   (e.g. this project's own test suites, which start from an empty
     *   database) — EXCEPT when resolveOrCreateFee()'s own name-only
     *   lookup would land on a test-data fixture that happens to share
     *   the catalog's exact name_ru (resolveOrCreateFee() has no concept
     *   of is_test_data at all, since import()'s other 5 categories never
     *   need one). That specific case is guarded against explicitly
     *   below — a test fixture must never be selected as "the"
     *   operational Fee merely because nothing else exists yet; a
     *   genuinely new, real Fee is created instead, and the test fixture
     *   is left completely untouched.
     * - More than one eligible candidate: fails closed — an automatic
     *   choice between two real, non-test Uniform Fees would be a guess,
     *   never a safe default. Nothing is created or written in this
     *   case; the surrounding transaction is rolled back by the caller's
     *   existing catch block.
     *
     * @param array<string,mixed> $definition
     * @param array{services_created:int,services_reused:int,tariffs_created:int,tariffs_skipped:int,conflicts:array<int,string>,dry_run:bool} $result
     */
    private function resolveOperationalUniformFee(array $definition, array &$result): ?Fee
    {
        $candidates = Fee::where('category', Fee::CATEGORY_UNIFORM)
            ->where('is_test_data', false)
            ->lockForUpdate()
            ->get();

        if ($candidates->count() > 1) {
            throw new RuntimeException('Найдено несколько активных услуг категории «uniform» — автоматический выбор невозможен, требуется ручное вмешательство.');
        }

        $fee = $candidates->first();
        if ($fee) {
            $result['services_reused']++;

            return $fee;
        }

        $nameMatch = Fee::where('name_ru', $definition['name'])->where('category', $definition['category'])->lockForUpdate()->first();
        if ($nameMatch && $nameMatch->is_test_data) {
            $fee = Fee::create([
                'name_ru' => $definition['name'],
                'category' => $definition['category'],
                'type' => $definition['type'],
                'payment_period' => $definition['service_period'],
                'amount' => '0.00',
                'description' => $definition['description'] ?? null,
                'is_active' => true,
            ]);
            $result['services_created']++;

            return $fee;
        }

        return $this->resolveOrCreateFee($definition, $result);
    }

    /**
     * Per-dimension idempotent upsert for one Fee's tariff variants,
     * shared by import() and importUniformOnly() — pure extraction of
     * import()'s original inline loop, no behavior change.
     *
     * @param array<int,array<string,mixed>> $tariffs
     * @param array{services_created:int,services_reused:int,tariffs_created:int,tariffs_skipped:int,conflicts:array<int,string>,dry_run:bool} $result
     */
    private function processTariffs(Fee $fee, AcademicYear $year, array $tariffs, array &$result): void
    {
        foreach ($tariffs as $variant) {
            $attributes = array_merge([
                'fee_id' => $fee->id,
                'academic_year_id' => $year->id,
                'amount' => $variant['amount'],
                'currency' => 'EGP',
                'start_date' => $year->start_date->toDateString(),
                'end_date' => $year->end_date->toDateString(),
                'grade_id' => null,
                'grade_group' => null,
                'payment_period' => null,
                'option_type' => null,
                'option_value' => null,
                'item' => null,
                'size' => null,
                'is_active' => true,
                'change_reason' => self::REASON,
            ], $variant);

            $dimensionFields = ['grade_id', 'grade_group', 'payment_period', 'option_type', 'option_value', 'item', 'size'];
            $dimensions = FeePrice::query()->where('fee_id', $fee->id)->where('academic_year_id', $year->id);
            foreach ($dimensionFields as $field) {
                $attributes[$field] === null
                    ? $dimensions->whereNull($field)
                    : $dimensions->where($field, $attributes[$field]);
            }

            $existing = (clone $dimensions)->lockForUpdate()->get();
            $exact = $existing->first(fn (FeePrice $price) =>
                $price->currency === 'EGP'
                && bccomp((string) $price->getRawOriginal('amount'), $attributes['amount'], 2) === 0
                && $price->start_date->toDateString() === $attributes['start_date']
                && $price->end_date?->toDateString() === $attributes['end_date']
            );

            if ($exact) {
                $result['tariffs_skipped']++;
                continue;
            }

            $overlap = $existing->first(fn (FeePrice $price) =>
                $price->is_active
                && $price->start_date->lte($attributes['end_date'])
                && ($price->end_date === null || $price->end_date->gte($attributes['start_date']))
            );

            if ($overlap) {
                $result['conflicts'][] = "Тариф для «{$fee->name_ru}» ({$this->variantLabel($attributes)}) пересекается с записью №{$overlap->id}.";
                continue;
            }

            FeePrice::create(collect($attributes)->except('option_label')->all());
            $result['tariffs_created']++;
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function catalog(): array
    {
        $tariffs = fn (array $rows): array => array_map(fn (array $row) => $row, $rows);

        return [
            ['name' => 'Организационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'type' => 'yearly', 'service_period' => Fee::PERIOD_ONCE, 'tariffs' => [['amount' => '7000.00', 'payment_period' => Fee::PERIOD_YEARLY]]],
            ['name' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'type' => 'service', 'service_period' => null, 'tariffs' => $tariffs($this->periodVariants([
                'Подготовительный класс' => ['33300.00', '3700.00'], '1–4 классы' => ['40500.00', '4500.00'], '5–6 классы' => ['49500.00', '5500.00'], '7–8 классы' => ['54900.00', '6100.00'], '9–11 классы' => ['67500.00', '7500.00'],
            ]))],
            ['name' => 'Трансфер', 'category' => Fee::CATEGORY_TRANSPORT, 'type' => 'service', 'service_period' => null, 'tariffs' => $tariffs($this->optionPeriodVariants([
                'Каусер, Мубарак 2, Интерконтиненталь' => ['13500.00', '1500.00'], 'Арабия, Мадарес, Шератон' => ['16200.00', '1800.00'], 'Мубарак 7, Эль-Хеляль, Эль-Ахья' => ['19800.00', '2200.00'],
            ], self::TRANSPORT_ZONE_OPTION_TYPE))],
            ['name' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'type' => 'service', 'service_period' => Fee::PERIOD_DAILY, 'tariffs' => $tariffs($this->mealPlanTariffs())],
            ['name' => 'Экстернат', 'category' => Fee::CATEGORY_TUITION_EXTERNAL, 'type' => 'service', 'service_period' => null, 'tariffs' => $tariffs($this->periodVariants(['1–4 классы' => ['25600.00', '3200.00']]))],
            ['name' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'type' => 'service', 'service_period' => Fee::PERIOD_PACKAGE, 'description' => 'Комплект: 2 майки + 1 поло + 1 толстовка', 'tariffs' => $tariffs(array_merge($this->uniformVariants(), $this->uniformIndividualSizeVariants()))],
        ];
    }

    /** @param array<string,array{0:string,1:string}> $groups */
    private function periodVariants(array $groups): array
    {
        $rows = [];
        foreach ($groups as $group => [$yearly, $monthly]) {
            $rows[] = ['amount' => $yearly, 'grade_group' => $group, 'payment_period' => Fee::PERIOD_YEARLY];
            $rows[] = ['amount' => $monthly, 'grade_group' => $group, 'payment_period' => Fee::PERIOD_MONTHLY];
        }
        return $rows;
    }

    /** @param array<string,array{0:string,1:string}> $groups */
    private function optionPeriodVariants(array $groups, string $optionType): array
    {
        $rows = [];
        foreach ($groups as $group => [$yearly, $monthly]) {
            $rows[] = ['amount' => $yearly, 'payment_period' => Fee::PERIOD_YEARLY, 'option_type' => $optionType, 'option_value' => $group];
            $rows[] = ['amount' => $monthly, 'payment_period' => Fee::PERIOD_MONTHLY, 'option_type' => $optionType, 'option_value' => $group];
        }
        return $rows;
    }

    /**
     * Food is priced per MealPlan — the real domain entity a student's
     * food subscription is recorded against (MealSubscription.meal_plan_id),
     * and the same convention Quick Registration already resolves prices
     * by (option_type='meal_plan', option_value=<meal_plan id>). The
     * three plans below are subscription-shaped (a daily meal_type over a
     * period), matching what MealPlan actually models; the previous
     * catalog's a-la-carte add-ons (soup, second course, a drink) don't
     * fit that shape and are intentionally dropped rather than forced
     * into a MealPlan record — a future a-la-carte extras category would
     * need its own dimension, out of scope here.
     *
     * @return array<int,array<string,mixed>>
     */
    private function mealPlanTariffs(): array
    {
        $plans = [
            ['name' => 'Комплексное питание', 'meal_type' => MealPlan::TYPE_BOTH, 'amount' => '170.00'],
            ['name' => 'Завтрак', 'meal_type' => MealPlan::TYPE_BREAKFAST, 'amount' => '70.00'],
            ['name' => 'Обед', 'meal_type' => MealPlan::TYPE_LUNCH, 'amount' => '100.00'],
        ];

        return array_map(function (array $definition): array {
            $plan = MealPlan::firstOrCreate(
                ['name_ru' => $definition['name']],
                ['meal_type' => $definition['meal_type'], 'period' => MealPlan::PERIOD_DAILY, 'price' => $definition['amount'], 'is_active' => true],
            );

            return [
                'amount' => $definition['amount'],
                'payment_period' => Fee::PERIOD_DAILY,
                'option_type' => self::MEAL_PLAN_OPTION_TYPE,
                'option_value' => (string) $plan->id,
                'option_label' => $definition['name'],
            ];
        }, $plans);
    }

    private function uniformVariants(): array
    {
        $groups = [
            '6–10' => ['Комплект' => '2000.00', 'Майка' => '400.00', 'Поло' => '600.00', 'Толстовка' => '900.00'],
            '12–16' => ['Комплект' => '2500.00', 'Майка' => '500.00', 'Поло' => '700.00', 'Толстовка' => '1200.00'],
            'от S' => ['Комплект' => '3000.00', 'Майка' => '500.00', 'Поло' => '800.00', 'Толстовка' => '1500.00'],
        ];
        $rows = [];
        foreach ($groups as $size => $items) {
            foreach ($items as $item => $amount) {
                $rows[] = ['amount' => $amount, 'payment_period' => Fee::PERIOD_ONCE, 'size' => $size, 'item' => $item];
            }
        }
        return $rows;
    }

    /**
     * Corrective pass — Uniform Procurement Report gap (business
     * requirement: factory procurement needs Item + EXACT size + quantity,
     * never a size range). uniformVariants() above is NEVER modified or
     * removed by this method — those 3 grouped-tier rows remain importable
     * exactly as before, so any historical FeePrice/invoice_item already
     * carrying '6–10'/'12–16'/'от S' stays fully readable and untouched
     * (requirement 5: legacy grouped values are preserved as historical
     * data, never silently reinterpreted). This method is purely additive:
     * new FeePrice rows, one per (item, individual exact size), imported
     * side by side with the legacy rows via the SAME idempotent per-
     * dimension upsert import() already performs — re-running import()
     * never duplicates or rewrites either set.
     *
     * Pricing note (explicit, not hidden): each exact size is priced at
     * its ORIGINATING tier's existing flat price — no new per-size price
     * was supplied by the business for this pass, so the safest, most
     * conservative choice is to carry the tier price over unchanged
     * rather than invent per-size figures. If the business later wants
     * genuinely different pricing per exact size, that is a distinct
     * pricing decision for someone to make explicitly, not something to
     * assume here.
     */
    private function uniformIndividualSizeVariants(): array
    {
        $tierPricesByItem = [
            '6–10' => ['Комплект' => '2000.00', 'Майка' => '400.00', 'Поло' => '600.00', 'Толстовка' => '900.00'],
            '12–16' => ['Комплект' => '2500.00', 'Майка' => '500.00', 'Поло' => '700.00', 'Толстовка' => '1200.00'],
            'от S' => ['Комплект' => '3000.00', 'Майка' => '500.00', 'Поло' => '800.00', 'Толстовка' => '1500.00'],
        ];
        $exactSizesByTier = [
            '6–10' => ['6', '8', '10'],
            '12–16' => ['12', '14', '16'],
            'от S' => ['S', 'M', 'L', 'XL'],
        ];

        $rows = [];
        foreach ($tierPricesByItem as $tier => $items) {
            foreach ($exactSizesByTier[$tier] as $size) {
                foreach ($items as $item => $amount) {
                    $rows[] = ['amount' => $amount, 'payment_period' => Fee::PERIOD_ONCE, 'size' => $size, 'item' => $item];
                }
            }
        }

        return $rows;
    }

    /**
     * Corrective pass P0 hardening (code review) — the tariffs loop above
     * records a genuine dimensional conflict (e.g. a director's
     * pre-existing manual tariff for one exact item+size) by skipping
     * that ONE row and appending to $result['conflicts'], never by
     * throwing — so the loop can finish normally even when one or more of
     * the 40 expected exact-size (item, size) combinations never got
     * created this run. Deactivating the legacy grouped rows in that
     * state would leave that one combination with NEITHER an active
     * legacy fallback NOR an exact-size replacement — silently losing
     * sellability for something that may be manually, legitimately
     * priced. So the legacy rows may only become inactive once the
     * COMPLETE expected (item, size) pair set is verified active —
     * checked as actual dimension pairs, not merely a count of rows
     * carrying one of the 10 exact size strings, which an unrelated or
     * duplicate row could otherwise falsely satisfy. Reused as-is by both
     * import() and importUniformOnly() — this method never needs to know
     * which caller invoked it.
     */
    private function deactivateLegacyUniformSizesIfReplacementComplete(Fee $fee, AcademicYear $year): void
    {
        $exactSizes = ['6', '8', '10', '12', '14', '16', 'S', 'M', 'L', 'XL'];

        $expectedPairs = collect($this->uniformIndividualSizeVariants())
            ->map(fn (array $row) => $row['item'].'|'.$row['size'])
            ->unique();

        $activePairs = FeePrice::where('fee_id', $fee->id)
            ->where('academic_year_id', $year->id)
            ->whereIn('size', $exactSizes)
            ->where('is_active', true)
            ->get(['item', 'size'])
            ->map(fn (FeePrice $price) => $price->item.'|'.$price->size)
            ->unique();

        $missing = $expectedPairs->diff($activePairs);

        if ($missing->isNotEmpty()) {
            // Incomplete replacement set — a normal, expected outcome of a
            // recorded conflict, never an error. Preserve previous
            // sellability: legacy rows stay exactly as they were.
            return;
        }

        FeePrice::where('fee_id', $fee->id)
            ->where('academic_year_id', $year->id)
            ->whereIn('size', ['6–10', '12–16', 'от S'])
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /** @param array<string,mixed> $attributes */
    private function variantLabel(array $attributes): string
    {
        $optionLabel = $attributes['option_label'] ?? $attributes['option_value'] ?? null;

        return implode(', ', array_filter([$attributes['grade_group'], $attributes['payment_period'], $optionLabel, $attributes['item'], $attributes['size']])) ?: 'общий';
    }
}
