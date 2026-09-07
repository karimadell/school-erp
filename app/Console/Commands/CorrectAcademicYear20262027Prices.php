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
