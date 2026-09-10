<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MasterData\MasterDataReconciliationApplyService;
use App\Services\MasterData\MasterDataReconciliationPlanner;
use App\Services\MasterData\MasterStaffImportService;
use App\Services\MasterData\MasterStudentImportService;
use App\Services\MasterData\ReconciliationPerformance;
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
        ReconciliationPerformance $performance,
    ): int {
        // PhpSpreadsheet currently emits known PHP 8.5 deprecations. Consume
        // only deprecations originating in that dependency; every other
        // deprecation, warning, error, and exception follows the prior handler.
        set_error_handler(
            fn (int $severity, string $message, string $file): bool => in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)
                && str_contains(str_replace('\\', '/', $file), '/vendor/phpoffice/phpspreadsheet/'),
            E_DEPRECATED | E_USER_DEPRECATED,
        );
        $queries = ['SELECT' => 0, 'INSERT' => 0, 'UPDATE' => 0, 'DELETE' => 0];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $verb = strtoupper(strtok(ltrim($query->sql), " \t\n\r"));
            if (array_key_exists($verb, $queries)) {
                $queries[$verb]++;
            }
        });

        $started = hrtime(true);
        $plan = null;
        $persistenceTransactionOpened = false;
        try {
            $studentPath = $this->option('student-path') ?: $students->defaultPath();
            $staffPath = $this->option('staff-path') ?: $staff->defaultPath();
            $transportPaths = $this->option('transport-path') ?: $transport->defaultPaths();
            $plan = $planner->plan($studentPath, $staffPath, $transportPaths);
            $planned = hrtime(true);
            $result = ['mode' => $this->option('apply') ? 'APPLY' : 'PREVIEW', 'preview' => $plan->summary()];

            if ($this->option('apply')) {
                if (! filled($this->option('actor-id'))) {
                    throw new \InvalidArgumentException('--actor-id is required with --apply.');
                }
                $actor = User::query()->where('is_active', true)->findOrFail((int) $this->option('actor-id'));
                $persistenceTransactionOpened = true;
                $result['apply'] = $apply->apply($actor, $plan);
            }

            $finished = hrtime(true);
            $result['performance'] = [
                'plan_seconds' => round(($planned - $started) / 1e9, 6),
                'total_seconds' => round(($finished - $started) / 1e9, 6),
                'workbook_physical_load_count' => $loader->physicalLoadCount(),
                'workbook_physical_loads' => $loader->physicalLoads(),
                'workbook_loads_inside_transaction' => 0,
                'select_count' => $queries['SELECT'],
                'insert_count' => $queries['INSERT'],
                'update_count' => $queries['UPDATE'],
                'delete_count' => $queries['DELETE'],
                'write_count' => $queries['INSERT'] + $queries['UPDATE'] + $queries['DELETE'],
            ] + $performance->report();

            $this->line(json_encode($this->option('json') ? $result : collect($result)->except('preview.baseline')->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (! $this->option('apply')) {
                $this->info('PREVIEW ONLY: zero writes. Use --apply explicitly to persist.');
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->line(json_encode([
                'mode' => $this->option('apply') ? 'APPLY' : 'READ_ONLY',
                'status' => 'FAILED',
                'stage_reached' => $performance->stage(),
                'elapsed_total_seconds' => round((hrtime(true) - $started) / 1e9, 6),
                'workbook_physical_load_count' => $loader->physicalLoadCount(),
                'workbook_physical_loads' => $loader->physicalLoads(),
                'persistence_transaction_opened' => $persistenceTransactionOpened,
                'plan_hash' => $plan?->planHash,
                'completed_stage_timings' => $performance->timings(),
                'query_counts' => array_change_key_case($queries, CASE_LOWER),
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            restore_error_handler();
        }
    }
}
