<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Services\Finance\UniformProductCatalogSyncService;
use Illuminate\Console\Command;

/**
 * Narrow, single-purpose catalog sync — writes ONLY `uniform_products`,
 * from the supplied AcademicYear's canonical exact-size Uniform FeePrice
 * rows. Never writes FeePrice, never touches Transport/Food/MealPlan/
 * PaymentPlan data. See UniformProductCatalogSyncService for the full
 * synchronization contract and fail-closed rules.
 *
 * Year selection is ALWAYS an explicit --year-id (a primary key), never a
 * name — this project's local database currently contains two
 * AcademicYear rows sharing the exact name "2026/2027", so any name-based
 * lookup here would be genuinely ambiguous.
 */
class SyncUniformProducts extends Command
{
    protected $signature = 'finance:sync-uniform-products
        {--year-id= : Explicit AcademicYear id to target — required, never resolved by name}
        {--dry-run : Only compute and print the plan; write nothing (default when neither mode is passed)}
        {--apply : Actually write the planned changes}';

    protected $description = 'Synchronize ONLY uniform_products (physical SKU catalog) from one AcademicYear\'s canonical exact-size Uniform FeePrice rows. Never writes FeePrice or touches Transport/Food/PaymentPlan data.';

    public function handle(UniformProductCatalogSyncService $service): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->components->error('Укажите либо --dry-run, либо --apply, но не оба одновременно.');

            return self::FAILURE;
        }

        $yearId = $this->option('year-id');
        if (blank($yearId)) {
            $this->components->error('Не указан --year-id. Учебный год должен быть указан явно по идентификатору — по умолчанию ничего не выбирается, и поиск по названию не поддерживается.');

            return self::FAILURE;
        }

        $year = AcademicYear::find((int) $yearId);
        if (! $year) {
            $this->components->error("Учебный год с id={$yearId} не найден.");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->components->info('Синхронизация каталога школьной формы (uniform_products) — '.($apply ? 'ПРИМЕНЕНИЕ' : 'ПРОВЕРКА (dry-run) — без записи'));
        $this->line("Учебный год: #{$year->id} {$year->name} ({$year->start_date->toDateString()} – {$year->end_date->toDateString()})");

        try {
            $result = $service->sync($year, $apply);
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $result['uniform_fee_id']) {
            $this->components->error('Операционная услуга категории «uniform» не найдена — синхронизация невозможна.');

            return self::FAILURE;
        }

        $this->line("Услуга «Школьная форма»: id={$result['uniform_fee_id']}");
        $this->newLine();

        $this->table(['Показатель', 'Значение'], [
            ['Ожидается комбинаций (товар × размер)', $result['expected_pairs']],
            ['Найдено в источнике (активные FeePrice)', $result['source_pairs']],
            ['Будет создано', $result['created']],
            ['Будет реактивировано', $result['reactivated']],
            ['Без изменений', $result['unchanged']],
            ['Будет деактивировано (устаревшие)', $result['deactivated']],
        ]);

        if ($result['missing'] !== []) {
            $this->components->error('Отсутствуют обязательные комбинации — синхронизация НЕ выполнена (ничего не записано):');
            foreach ($result['missing'] as $pair) {
                $this->line('  - '.$pair);
            }
        }

        if ($result['ambiguous_source_pairs'] !== []) {
            $this->components->error('Найдены неоднозначные (дублирующиеся) активные тарифы — синхронизация НЕ выполнена (ничего не записано):');
            foreach ($result['ambiguous_source_pairs'] as $pair) {
                $this->line('  - '.$pair);
            }
        }

        if ($result['duplicate_catalog_pairs'] !== []) {
            $this->components->error('Найдены дублирующиеся записи каталога (uniform_products) с одинаковой идентичностью — синхронизация НЕ выполнена (ничего не записано):');
            foreach ($result['duplicate_catalog_pairs'] as $pair) {
                $this->line('  - '.$pair);
            }
        }

        $failedClosed = $result['missing'] !== [] || $result['ambiguous_source_pairs'] !== [] || $result['duplicate_catalog_pairs'] !== [];
        if ($failedClosed) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->line($apply
            ? ($result['applied'] ? 'Синхронизация выполнена. Изменена только категория «Школьная форма» (uniform_products); тарифы (FeePrice) не изменялись.' : 'Ничего не применено.')
            : 'Проверка завершена — база данных не изменена. Запустите с --apply для реального применения.');

        return self::SUCCESS;
    }
}
