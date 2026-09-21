<?php

namespace App\Console\Commands;

use App\Services\Finance\AcademicYear20262027PriceCorrectiveService;
use Illuminate\Console\Command;

class CorrectAcademicYear20262027Prices extends Command
{
    protected $signature = 'finance:correct-2026-2027-prices {--year-id= : Required AcademicYear primary key} {--dry-run : Preview only} {--apply : Apply transactionally}';

    protected $description = 'Apply the approved 2026/2027 price list to one explicit AcademicYear id.';

    public function handle(AcademicYear20262027PriceCorrectiveService $service): int
    {
        if (($this->option('dry-run') && $this->option('apply')) || blank($this->option('year-id'))) {
            $this->error('Provide --year-id and exactly one of --dry-run or --apply (default is dry-run).');

            return self::FAILURE;
        }
        try {
            $preview = $service->run((int) $this->option('year-id'), false);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->table(['Key', 'Before', 'After'], array_map(fn ($c) => [$c['key'], $c['before'], $c['after']], $preview['changes']));

        if (! empty($preview['food_report'])) {
            $this->table(
                ['FeePrice ID', 'Food item', 'option_value', 'payment_period before', 'payment_period after', 'amount before', 'amount after'],
                array_map(fn ($r) => [
                    $r['fee_price_id'], $r['name'], $r['option_value'],
                    $r['payment_period_before'], $r['payment_period_after'],
                    $r['amount_before'], $r['amount_after'],
                ], $preview['food_report']),
            );
        }

        if (! $this->option('apply')) {
            $this->info('Dry-run: zero writes.');

            return self::SUCCESS;
        }

        try {
            $service->run((int) $this->option('year-id'), true);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Applied.');

        return self::SUCCESS;
    }
}
