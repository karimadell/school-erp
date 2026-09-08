<?php

namespace App\Console\Commands;

use App\Services\Transport\TransportImportPreviewService;
use Illuminate\Console\Command;

class TransportImportPreview extends Command
{
    protected $signature = 'transport:import-preview {--path=* : XLSX source workbook path (repeatable)} {--json : Print the complete machine-readable preview}';

    protected $description = 'Read-only transport workbook parsing and canonical matching preview; never imports data.';

    public function handle(TransportImportPreviewService $preview): int
    {
        $paths = array_values(array_filter($this->option('path')));
        if ($paths === []) {
            $this->error('Provide one or more --path=/path/to/source.xlsx files. No operational data was written.');

            return self::FAILURE;
        }
        try {
            $result = $preview->preview($paths);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->info('Transport import preview (READ-ONLY; zero operational writes)');
        $this->table(['Metric', 'Value'], collect($result['summary'])->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v])->all());
        $this->table(['Route', 'Students', 'Staff', 'Unknown', 'Matched', 'Unmatched', 'Ambiguous', 'Capacity'], array_map(fn ($r) => [$r['route'], $r['students'], $r['staff'], $r['unknown'], $r['matched_students'], $r['unmatched_students'], $r['ambiguous_students'], $r['status']], $result['capacity']));

        return self::SUCCESS;
    }
}
