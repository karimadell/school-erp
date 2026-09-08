<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MasterData\MasterStudentImportService;
use Illuminate\Console\Command;

class MasterDataStudents extends Command
{
    protected $signature = 'master-data:students {--path=} {--apply} {--actor-id=} {--json}';

    protected $description = 'Preview or explicitly apply the authoritative 2026/2027 student master list.';

    public function handle(MasterStudentImportService $service): int
    {
        try {
            $path = $this->option('path') ?: null;
            if ($this->option('apply')) {
                $actor = User::where('is_active', true)->findOrFail((int) $this->option('actor-id'));
                $r = $service->apply($actor, $path);
            } else {
                $r = $service->preview($path);
            }$this->line($this->option('json') ? json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : json_encode(collect($r)->except('rows')->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if (! $this->option('apply')) {
                $this->info('PREVIEW ONLY: zero writes.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
