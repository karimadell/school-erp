<?php

namespace App\Console\Commands;

use App\Services\Finance\SchoolPriceListImportService;
use Illuminate\Console\Command;

class ImportSchoolPriceList2025_2026 extends Command
{
    protected $signature = 'finance:import-price-list-2025-2026 {--force : Выполнить без подтверждения} {--dry-run : Только проверить, не сохраняя изменения}';
    protected $description = 'Импорт официального прайс-листа школы за 2025/2026 учебный год';

    public function handle(SchoolPriceListImportService $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info('Импорт прайс-листа ЦЕНТРА «НАШИ ТРАДИЦИИ» за 2025/2026 учебный год. Валюта: EGP.');

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('Создать отсутствующие услуги и тарифы? Существующие записи не будут изменены.')) {
            $this->warn('Импорт отменён.');
            return self::SUCCESS;
        }

        try {
            $result = $importer->import($dryRun);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->table(['Показатель', 'Количество'], [
            ['Создано услуг', $result['services_created']], ['Использовано существующих услуг', $result['services_reused']],
            ['Создано тарифов', $result['tariffs_created']], ['Пропущено существующих тарифов', $result['tariffs_skipped']],
            ['Обнаружено конфликтов', count($result['conflicts'])],
        ]);
        foreach ($result['conflicts'] as $conflict) {
            $this->error($conflict);
        }
        $this->line($dryRun ? 'Проверка завершена. База данных не изменена.' : 'Импорт завершён. Исторические счета и платежи не изменялись.');

        return $result['conflicts'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
