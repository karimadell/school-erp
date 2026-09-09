<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Transport\RealStaffTransportAssignmentBootstrapService;
use Illuminate\Console\Command;

class RealStaffTransportAssignmentBootstrap extends Command
{
    protected $signature = 'transport:real-staff-assignments
        {--path=* : Approved XLSX path; defaults to storage/app/transport-import/*.xlsx}
        {--master-path= : Authoritative master Staff XLSX path}
        {--apply : Explicitly create resolved staff-passenger assignments}
        {--actor-id= : Authorized active user recorded as assignment creator}
        {--json : Print complete preview details}';

    protected $description = 'Audit, preview, or explicitly apply source-keyed real staff transport assignments.';

    public function handle(RealStaffTransportAssignmentBootstrapService $service): int
    {
        $paths = array_values(array_filter($this->option('path')));
        try {
            if ($this->option('apply')) {
                $actorId = filter_var($this->option('actor-id'), FILTER_VALIDATE_INT);
                if (! $actorId || ! ($actor = User::query()->where('is_active', true)->find($actorId))) {
                    throw new \InvalidArgumentException('--apply requires a valid active --actor-id.');
                }
                $result = $service->apply($actor, $paths, $this->option('master-path') ?: app(\App\Services\MasterData\MasterStaffImportService::class)->defaultPath());
            } else {
                $result = $service->preview($paths, $this->option('master-path') ?: app(\App\Services\MasterData\MasterStaffImportService::class)->defaultPath());
            }
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->table(['Metric', 'Value'], collect($result)->except('rows')->map(fn ($value, $key) => [$key, is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)])->all());
            }
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
