<?php

namespace App\Console\Commands;

use App\Services\Finance\AcademicYear20262027TuitionPriceDeploymentService;
use Illuminate\Console\Command;

class DeployAcademicYear20262027TuitionPrices extends Command
{
    protected $signature = 'finance:deploy-tuition-prices-2026-2027
        {--apply : Persist the validated matrix atomically. Default is a true read-only dry-run.}';

    protected $description = 'Inspect or atomically deploy the approved 2026/2027 EnrollmentMode-scoped Tuition price matrix.';

    public function handle(AcademicYear20262027TuitionPriceDeploymentService $service): int
    {
        $apply = (bool) $this->option('apply');
        $this->components->info($apply ? 'APPLY — guarded Tuition price deployment' : 'DRY-RUN / ZERO WRITES');

        try {
            $result = $apply ? $service->apply() : $service->plan();
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('AcademicYear', "#{$result['academic_year']['id']} {$result['academic_year']['name']} ({$result['academic_year']['start_date']} – {$result['academic_year']['end_date']})");
        $this->components->twoColumnDetail('Unified Tuition Fee', "#{$result['fee']['id']} {$result['fee']['name']}");
        $this->components->twoColumnDetail('EnrollmentModes', implode(', ', $result['enrollment_modes']));
        $this->components->twoColumnDetail('Allowed billing periods', implode(', ', $result['allowed_billing_periods']));
        $this->components->twoColumnDetail('Generic Tuition rows preserved', (string) $result['generic_rows']);

        $this->newLine();
        $this->table(
            ['Mode', 'Grade group', 'Period', 'Amount', 'Start', 'End', 'Status'],
            array_map(fn (array $row) => [
                $row['option_value'], $row['grade_group'], $row['payment_period'], $row['amount'],
                $row['start_date'], $row['end_date'], $row['status'],
            ], $result['rows']),
        );

        $this->table(['CREATE', 'IDENTICAL', 'CONFLICT', 'TOTAL'], [[
            $result['totals']['CREATE'],
            $result['totals']['IDENTICAL'],
            $result['totals']['CONFLICT'],
            count($result['rows']),
        ]]);

        foreach ($result['conflicts'] as $conflict) {
            $this->components->error($conflict);
        }

        if ($result['conflicts'] !== []) {
            $this->components->error('Conflicts detected. No writes were performed.');

            return self::FAILURE;
        }

        if ($apply) {
            $this->components->info("Committed and verified exactly {$result['verified_count']} intended rows ({$result['created_count']} created; {$result['totals']['IDENTICAL']} already identical). No existing FeePrice was modified.");
        } else {
            $this->components->info('Dry-run complete: zero writes. Re-run with --apply only after reviewing this plan.');
        }

        return self::SUCCESS;
    }
}
