<?php

namespace App\Console\Commands;

use App\Services\Admissions\RealStudentBootstrapService;
use Illuminate\Console\Command;

class RealStudentBootstrap extends Command
{
    protected $signature = 'students:real-bootstrap {--path=* : Transport XLSX path (repeatable)} {--apply : Explicitly create Students and Enrollments}';

    protected $description = 'Preview or explicitly apply the non-Finance real student bootstrap.';

    public function handle(RealStudentBootstrapService $service): int
    {
        $paths = array_values(array_filter($this->option('path')));
        if ($paths === []) {
            $this->error('Provide --path for each source workbook.');

            return self::FAILURE;
        }
        try {
            $result = $this->option('apply') ? $service->apply($paths) : $service->preview($paths);
            $this->table(['Metric', 'Value'], collect($result)->except('rows')->map(fn ($v, $k) => [$k, is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)])->all());
            if (! $this->option('apply')) {
                $this->info('PREVIEW ONLY: zero operational writes.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
