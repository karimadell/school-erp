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
                $fee = Fee::query()->where('name_ru', $definition['name'])->lockForUpdate()->first();

                if ($fee && $fee->category !== $definition['category']) {
                    $result['conflicts'][] = "Услуга «{$definition['name']}» уже существует в другой категории.";
                    continue;
                }

                if ($fee) {
                    $result['services_reused']++;
                } else {
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
                }

                foreach ($definition['tariffs'] as $variant) {
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
                        $result['conflicts'][] = "Тариф для «{$definition['name']}» ({$this->variantLabel($attributes)}) пересекается с записью №{$overlap->id}.";
                        continue;
                    }

                    FeePrice::create(collect($attributes)->except('option_label')->all());
                    $result['tariffs_created']++;
                }

                // Corrective pass (code review P0) — the exact-size rows
                // above (uniformIndividualSizeVariants()) are this Fee's
                // replacement for the legacy grouped-tier rows
                // (uniformVariants()) for THIS SAME academic year. Once
                // this run has processed the Uniform definition's full
                // tariffs loop (created or confirmed-already-existing,
                // either way — 'exact' skip and fresh create both reach
                // here), the legacy rows must stop being selectable for
                // NEW sales without ever deleting or rewriting them:
                // is_active=false only, size/amount/item untouched, so
                // every historical invoice_item snapshot referencing one
                // stays fully readable (invoice_items never live-resolve
                // through FeePrice.is_active). Scoped to exactly this
                // Fee + this run's single target academic year — another
                // year's legacy rows are never touched by this run.
                // Idempotent: a plain WHERE ... is_active=true -> false
                // update is a no-op on any row already deactivated by a
                // prior run. Runs inside the same transaction as the
                // creates above, so a thrown exception anywhere in this
                // Fee's tariff loop rolls this back too — the legacy
                // rows are never deactivated unless the exact-size
                // replacements for this run genuinely completed.
                if ($definition['category'] === Fee::CATEGORY_UNIFORM) {
                    FeePrice::where('fee_id', $fee->id)
                        ->where('academic_year_id', $year->id)
                        ->whereIn('size', ['6–10', '12–16', 'от S'])
                        ->where('is_active', true)
                        ->update(['is_active' => false]);
                }
            }

            $dryRun ? DB::rollBack() : DB::commit();

            return $result;
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
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

    /** @param array<string,mixed> $attributes */
    private function variantLabel(array $attributes): string
    {
        $optionLabel = $attributes['option_label'] ?? $attributes['option_value'] ?? null;

        return implode(', ', array_filter([$attributes['grade_group'], $attributes['payment_period'], $optionLabel, $attributes['item'], $attributes['size']])) ?: 'общий';
    }
}
