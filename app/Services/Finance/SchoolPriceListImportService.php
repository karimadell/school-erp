<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SchoolPriceListImportService
{
    public const YEAR = '2025/2026';
    public const REASON = 'Первоначальный импорт прайс-листа 2025/2026';

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

                    FeePrice::create($attributes);
                    $result['tariffs_created']++;
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
            ], 'Район'))],
            ['name' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'type' => 'service', 'service_period' => Fee::PERIOD_DAILY, 'tariffs' => $tariffs(array_map(fn ($item, $amount) => ['amount' => $amount, 'payment_period' => Fee::PERIOD_DAILY, 'item' => $item], array_keys($meals = ['Комплексное питание' => '170.00', 'Завтрак' => '70.00', 'Обед' => '100.00', 'Суп' => '20.00', 'Второе блюдо' => '80.00', 'Напиток' => '10.00']), array_values($meals)))],
            ['name' => 'Экстернат', 'category' => Fee::CATEGORY_TUITION_EXTERNAL, 'type' => 'service', 'service_period' => null, 'tariffs' => $tariffs($this->periodVariants(['1–4 классы' => ['25600.00', '3200.00']]))],
            ['name' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'type' => 'service', 'service_period' => Fee::PERIOD_PACKAGE, 'description' => 'Комплект: 2 майки + 1 поло + 1 толстовка', 'tariffs' => $tariffs($this->uniformVariants())],
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

    /** @param array<string,mixed> $attributes */
    private function variantLabel(array $attributes): string
    {
        return implode(', ', array_filter([$attributes['grade_group'], $attributes['payment_period'], $attributes['option_value'], $attributes['item'], $attributes['size']])) ?: 'общий';
    }
}
