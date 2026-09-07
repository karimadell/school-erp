<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Services\Finance\TransportZoneOptionTypeNormalizationService;
use Illuminate\Console\Command;

/**
 * Narrow, single-purpose data correction — updates ONLY
 * `fee_prices.option_type` on active Transport FeePrice rows from the
 * known legacy value ('Район') to the current canonical value ('zone').
 * Never writes amount, option_value, payment_period, dates, or any other
 * column; never creates or deletes a FeePrice row; never touches
 * transport_routes, Food, Uniform, or PaymentPlan data. See
 * TransportZoneOptionTypeNormalizationService for the full contract and
 * fail-closed rules.
 *
 * Year selection is ALWAYS an explicit --year-id (a primary key), never a
 * name — this project's local database has previously contained more
 * than one AcademicYear row sharing an identical name, so any name-based
 * lookup here would be unsafe.
 */
class NormalizeTransportZoneOptionType extends Command
{
    protected $signature = 'finance:normalize-transport-zone-option-type
        {--year-id= : Explicit AcademicYear id to target — required, never resolved by name}
        {--dry-run : Only compute and print the plan; write nothing (default when neither mode is passed)}
        {--apply : Actually write the planned changes}';

    protected $description = "Normalize legacy Transport FeePrice option_type ('Район') to the canonical value ('zone') for one explicit AcademicYear. Never writes amount/option_value/payment_period/dates or touches transport_routes/Food/Uniform/PaymentPlan data.";

    public function handle(TransportZoneOptionTypeNormalizationService $service): int
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
        $this->components->info('Нормализация option_type транспортных тарифов (Район → zone) — '.($apply ? 'ПРИМЕНЕНИЕ' : 'ПРОВЕРКА (dry-run) — без записи'));
        $this->line("Учебный год: #{$year->id} {$year->name} ({$year->start_date->toDateString()} – {$year->end_date->toDateString()})");

        try {
            $result = $service->normalize($year, $apply);
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $result['transport_fee_id']) {
            $this->components->error('Операционная услуга категории «transport» не найдена — нормализация невозможна.');

            return self::FAILURE;
        }

        $this->line("Услуга «Трансфер»: id={$result['transport_fee_id']}");
        $this->newLine();

        $this->table(['Показатель', 'Значение'], [
            ['Найдено legacy (option_type=\'Район\')', $result['legacy_found']],
            ['Найдено canonical (option_type=\'zone\')', $result['canonical_found']],
            ['Найдено неожиданных option_type', $result['unexpected_found']],
            ['Пригодно к нормализации', $result['eligible']],
            ['Нормализовано', $result['normalized']],
        ]);

        if ($result['legacy_rows'] !== []) {
            $this->line('Legacy строки:');
            $this->table(['id', 'зона (option_value)', 'период оплаты'], collect($result['legacy_rows'])->map(fn ($r) => [$r['id'], $r['option_value'], $r['payment_period'] ?? '—']));
        }

        if ($result['unexpected_option_types'] !== []) {
            $this->components->error('Найдены неожиданные значения option_type — нормализация НЕ выполнена (ничего не записано):');
            foreach ($result['unexpected_option_types'] as $row) {
                $this->line("  - price id={$row['id']}: option_type=".($row['option_type'] ?? '(пусто)'));
            }
        }

        if ($result['conflicts'] !== []) {
            $this->components->error('Найдены конфликтующие/неоднозначные тарифы — нормализация НЕ выполнена (ничего не записано):');
            foreach ($result['conflicts'] as $conflict) {
                $this->line("  - {$conflict['identity']}: legacy ids=[".implode(',', $conflict['legacy_ids']).'] canonical ids=['.implode(',', $conflict['canonical_ids']).']');
            }
        }

        $failedClosed = $result['unexpected_option_types'] !== [] || $result['conflicts'] !== [];
        if ($failedClosed) {
            return self::FAILURE;
        }

        if ($result['legacy_found'] === 0) {
            $this->newLine();
            $this->line('Legacy строк не найдено — нормализация не требуется (уже канонический вид или отсутствуют тарифы).');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($apply
            ? ($result['applied'] ? 'Нормализация выполнена. Изменено только поле option_type; сумма, зона, период и даты не менялись.' : 'Ничего не применено.')
            : 'Проверка завершена — база данных не изменена. Запустите с --apply для реального применения.');

        return self::SUCCESS;
    }
}
