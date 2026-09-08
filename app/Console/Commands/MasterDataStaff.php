<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MasterData\MasterStaffImportService;
use Illuminate\Console\Command;

class MasterDataStaff extends Command
{
    protected $signature = 'master-data:staff {--path=} {--transport-path=*} {--apply} {--actor-id=} {--json}';

    protected $description = 'Preview or explicitly apply authentication-independent staff master identities.';

    public function handle(MasterStaffImportService $service): int
    {
        try {
            $path = $this->option('path') ?: null;
            $transportPaths = $this->option('transport-path') ?: null;
            if ($this->option('apply')) {
                $actor = User::where('is_active', true)->findOrFail((int) $this->option('actor-id'));
                $r = $service->apply($actor, $path, $transportPaths);
            } else {
                $r = $service->preview($path, $transportPaths);
            }
            $this->line(json_encode($this->option('json') ? $r : collect($r)->except('staff')->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
