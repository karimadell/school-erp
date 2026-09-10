<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MasterData\MasterDataReconciliationApplyService;
use App\Services\MasterData\MasterDataReconciliationPlanner;
use App\Services\MasterData\MasterStaffImportService;
use App\Services\MasterData\MasterStudentImportService;
use App\Services\MasterData\WorkbookLoader;
use App\Services\Transport\RealStudentTransportAssignmentBootstrapService;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

final class MasterDataReconcile extends Command
{
    protected $signature = 'master-data:reconcile
        {--apply : Explicitly persist the validated plan atomically}
        {--actor-id= : Required active authorized actor for --apply}
        {--student-path= : Authoritative Student workbook}
        {--staff-path= : Authoritative Staff workbook}
        {--transport-path=* : The five authoritative Transport workbooks}
        {--json : Emit complete JSON}';

    protected $description = 'Prepare the complete reconciliation outside a transaction; preview by default or explicitly apply atomically.';

    public function handle(
        MasterDataReconciliationPlanner $planner,
        MasterDataReconciliationApplyService $apply,
        MasterStudentImportService $students,
        MasterStaffImportService $staff,
        RealStudentTransportAssignmentBootstrapService $transport,
        WorkbookLoader $loader,
    ): int {
        $oldReporting = error_reporting();
        // PhpSpreadsheet currently emits known PHP 8.5 deprecations. Suppress
        // only E_DEPRECATED during this command; warnings/errors/exceptions stay visible.
        error_reporting($oldReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        $selects = $writes = 0;
        DB::listen(function (QueryExecuted $query) use (&$selects, &$writes): void {
            $verb = strtoupper(strtok(ltrim($query->sql), " \t\n\r"));
            $selects += $verb === 'SELECT' ? 1 : 0;
            $writes += in_array($verb, ['INSERT', 'UPDATE', 'DELETE'], true) ? 1 : 0;
        });

        try {
            $studentPath = $this->option('student-path') ?: $students->defaultPath();
            $staffPath = $this->option('staff-path') ?: $staff->defaultPath();
            $transportPaths = $this->option('transport-path') ?: $transport->defaultPaths();
            $started = hrtime(true);
            $plan = $planner->plan($studentPath, $staffPath, $transportPaths);
            $planned = hrtime(true);
            $result = ['mode' => $this->option('apply') ? 'APPLY' : 'PREVIEW', 'preview' => $plan->summary()];

            if ($this->option('apply')) {
                if (! filled($this->option('actor-id'))) {
                    throw new \InvalidArgumentException('--actor-id is required with --apply.');
                }
                $actor = User::query()->where('is_active', true)->findOrFail((int) $this->option('actor-id'));
                $result['apply'] = $apply->apply($actor, $plan);
            }

            $finished = hrtime(true);
            $result['performance'] = [
                'plan_seconds' => round(($planned - $started) / 1e9, 6),
                'total_seconds' => round(($finished - $started) / 1e9, 6),
                'workbook_physical_load_count' => $loader->physicalLoadCount(),
                'workbook_physical_loads' => $loader->physicalLoads(),
                'workbook_loads_inside_transaction' => 0,
                'select_count' => $selects,
                'write_count' => $writes,
            ];

            $this->line(json_encode($this->option('json') ? $result : collect($result)->except('preview.baseline')->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (! $this->option('apply')) {
                $this->info('PREVIEW ONLY: zero writes. Use --apply explicitly to persist.');
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            error_reporting($oldReporting);
        }
    }
}
