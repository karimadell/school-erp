<?php

namespace App\Console\Commands;

use App\Services\Transport\RealTransportCatalogBootstrapService;
use Illuminate\Console\Command;

class RealTransportCatalogBootstrap extends Command
{
    protected $signature = 'transport:catalog-bootstrap {--apply : Explicitly create the five routes and vehicles}';

    protected $description = 'Preview or explicitly apply the approved local Transport route/vehicle catalog.';

    public function handle(RealTransportCatalogBootstrapService $service): int
    {
        try {
            $result = $this->option('apply') ? $service->apply() : $service->preview();
            $this->table(['Metric', 'Value'], collect($result)->except('routes')->map(fn ($value, $key) => [$key, is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)])->all());
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
