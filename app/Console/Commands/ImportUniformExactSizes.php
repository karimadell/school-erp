<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Services\Finance\SchoolPriceListImportService;
use Illuminate\Console\Command;

/**
 * Year-scoped, single-category master-data import — Uniform only.
 *
 * SchoolPriceListImportService::import() is hard-targeted to the fixed
 * historical academic year SchoolPriceListImportService::YEAR
 * ('2025/2026') and processes every catalog() category (Registration,
 * Tuition, Transport, Food, Externat, Uniform) in one pass. On an
 * environment whose real academic year is a different name (e.g.
 * '2026/2027'), that command fails closed exactly as designed — it must
 * never be retargeted wholesale, since that would also attempt to create
 * Registration/Tuition/Transport/Food/Externat tariffs from the 2025/2026
 * catalog's prices against a year that may already carry its own
 * independently-curated data for those categories.
 *
 * This command exists for exactly the narrow need the Uniform exact-size
 * corrective pass introduced: apply ONLY the reviewed Uniform exact-size
 * master data (SchoolPriceListImportService::importUniformOnly()) to an
 * existing academic year, without touching any other category. The year
 * is always explicit — there is no default, and an unrecognised year
 * fails closed exactly like the full importer does for its own hardcoded
 * year.
 */
class ImportUniformExactSizes extends Command
{
    protected $signature = 'finance:import-uniform-exact-sizes
        {--year= : Exact academic year name to target, e.g. "2026/2027" — required, never defaulted}
        {--force : Выполнить без подтверждения}
        {--dry-run : Только проверить, не сохраняя изменения}';

    protected $description = 'Uniform-only master-data import (exact sizes + legacy-size deactivation) for an explicit academic year. Never touches Registration/Tuition/Transport/Food/Externat.';

    private const EXACT_SIZES = ['6', '8', '10', '12', '14', '16', 'S', 'M', 'L', 'XL'];

    private const LEGACY_SIZES = ['6–10', '12–16', 'от S'];

    public function handle(SchoolPriceListImportService $importer): int
    {
        $academicYearName = $this->option('year');
        if (blank($academicYearName)) {
            $this->error('Не указан --year. Учебный год должен быть указан явно — по умолчанию ничего не выбирается.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info("Импорт точных размеров школьной формы за {$academicYearName} учебный год (только категория «Школьная форма»). Валюта: EGP.");

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('Создать/обновить только тарифы школьной формы для указанного учебного года? Остальные категории (Обучение, Транспорт, Питание, Экстернат, Регистрация) не будут затронуты.')) {
            $this->warn('Импорт отменён.');

            return self::SUCCESS;
        }

        try {
            $result = $importer->importUniformOnly($academicYearName, $dryRun);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Показатель', 'Количество'], [
            ['Учебный год', $academicYearName],
            ['Создано услуг', $result['services_created']],
            ['Использовано существующих услуг', $result['services_reused']],
            ['Создано тарифов', $result['tariffs_created']],
            ['Пропущено существующих тарифов', $result['tariffs_skipped']],
            ['Обнаружено конфликтов', count($result['conflicts'])],
        ]);
        foreach ($result['conflicts'] as $conflict) {
            $this->error($conflict);
        }

        // Reported fresh from the DB after the run (not from $result, which
        // only counts this run's own creates/skips) so the operator always
        // sees the true current state — including rows created by an
        // earlier run.
        [$activeExactCount, $activeLegacyCount] = $this->currentCounts($academicYearName);
        $this->newLine();
        $this->line("Активных точных комбинаций товар+размер: {$activeExactCount} из 40.");
        $this->line('Активных устаревших сгруппированных размеров (6–10 / 12–16 / от S): '.$activeLegacyCount.'.');

        if ($activeExactCount < 40 && $activeLegacyCount > 0) {
            $this->warn('Полная замена точными размерами ещё не завершена — устаревшие сгруппированные тарифы остаются активными как резервный вариант продажи. Это ожидаемое, безопасное поведение, а не ошибка.');
        }

        $this->line($dryRun ? 'Проверка завершена. База данных не изменена.' : 'Импорт завершён. Исторические счета и платежи не изменялись; затронута только категория «Школьная форма».');

        return $result['conflicts'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0:int,1:int} */
    private function currentCounts(string $academicYearName): array
    {
        $year = AcademicYear::where('name', $academicYearName)->first();
        $fee = Fee::where('name_ru', 'Школьная форма')->where('category', Fee::CATEGORY_UNIFORM)->first();

        if (! $year || ! $fee) {
            return [0, 0];
        }

        $activeExact = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->whereIn('size', self::EXACT_SIZES)->where('is_active', true)->count();
        $activeLegacy = FeePrice::where('fee_id', $fee->id)->where('academic_year_id', $year->id)
            ->whereIn('size', self::LEGACY_SIZES)->where('is_active', true)->count();

        return [$activeExact, $activeLegacy];
    }
}
