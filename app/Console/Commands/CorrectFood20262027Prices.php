<?php

namespace App\Console\Commands;

use App\Services\Finance\AcademicYear20262027PriceCorrectiveService;
use Illuminate\Console\Command;

/**
 * Food-only execution path for the owner-approved 2026/2027 Food
 * price/payment_period correction (Option B). Deliberately narrower than
 * finance:correct-2026-2027-prices — it calls
 * AcademicYear20262027PriceCorrectiveService::runFoodOnly(), which has its
 * own transaction boundary and executes ONLY the shared, already-reviewed
 * Food logic. It can never reach Registration, Tuition, Transport,
 * Uniform, or After-School, so it stays usable even while those other
 * categories have their own, independent, unrelated UAT incompatibilities.
 */
class CorrectFood20262027Prices extends Command
{
    protected $signature = 'finance:correct-food-2026-2027
        {--year-id= : Required AcademicYear primary key}
        {--dry-run : Preview only (default — no writes)}
        {--apply : Apply transactionally}';

    protected $description = 'Apply the owner-approved 2026/2027 Food price/payment_period correction ONLY — never touches Registration, Tuition, Transport, Uniform, or After-School. Default dry-run, --apply required to write.';

    public function handle(AcademicYear20262027PriceCorrectiveService $service): int
    {
        if (($this->option('dry-run') && $this->option('apply')) || blank($this->option('year-id'))) {
            $this->error('Provide --year-id and exactly one of --dry-run or --apply (default is dry-run).');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');

        try {
            $preview = $service->runFoodOnly((int) $this->option('year-id'), false);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printFoodReport($preview['food_report']);

        if (! $apply) {
            $this->components->warn('DRY-RUN ONLY — no data was created, updated, or deleted. Re-run with --apply to write.');

            return self::SUCCESS;
        }

        try {
            $service->runFoodOnly((int) $this->option('year-id'), true);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Apply complete. Only the six canonical Food fee_prices rows (amount, payment_period) and their MealPlan.price display values were touched — Registration, Tuition, Transport, Uniform, and After-School were never invoked.');

        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $report */
    private function printFoodReport(array $report): void
    {
        $this->table(
            ['FeePrice ID', 'Food item', 'option_value', 'payment_period', 'amount', 'MealPlan ID', 'MealPlan.price', 'status'],
            array_map(function (array $r): array {
                $changed = $r['payment_period_before'] !== $r['payment_period_after']
                    || bccomp($r['amount_before'], $r['amount_after'], 2) !== 0
                    || bccomp($r['meal_plan_price_before'], $r['meal_plan_price_after'], 2) !== 0;

                return [
                    $r['fee_price_id'],
                    $r['name'],
                    $r['option_value'],
                    "{$r['payment_period_before']} -> {$r['payment_period_after']}",
                    "{$r['amount_before']} -> {$r['amount_after']}",
                    $r['meal_plan_id'],
                    "{$r['meal_plan_price_before']} -> {$r['meal_plan_price_after']}",
                    $changed ? 'CHANGE' : 'NO CHANGE',
                ];
            }, $report),
        );
    }
}
