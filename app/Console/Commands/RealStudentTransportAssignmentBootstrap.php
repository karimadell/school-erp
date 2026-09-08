<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Transport\RealStudentTransportAssignmentBootstrapService;
use Illuminate\Console\Command;

class RealStudentTransportAssignmentBootstrap extends Command
{
    protected $signature = 'transport:real-student-assignments
        {--path=* : Approved transport XLSX path; defaults to storage/app/transport-import/*.xlsx}
        {--apply : Explicitly create assignments}
        {--actor-id= : Authorized active user recorded as assignment creator}';

    protected $description = 'Preview or explicitly apply the source-keyed real student transport assignments.';

    public function handle(RealStudentTransportAssignmentBootstrapService $service): int
    {
        $paths = array_values(array_filter($this->option('path')));

        try {
            if ($this->option('apply')) {
                $actorId = filter_var($this->option('actor-id'), FILTER_VALIDATE_INT);
                if (! $actorId || ! ($actor = User::query()->where('is_active', true)->find($actorId))) {
                    throw new \InvalidArgumentException('--apply requires a valid active --actor-id.');
                }
                $result = $service->apply($actor, $paths);
            } else {
                $result = $service->preview($paths);
            }

            $this->table(['Metric', 'Value'], collect($result)->except('rows')->map(fn ($value, $key) => [
                $key, is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE),
            ])->all());
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
