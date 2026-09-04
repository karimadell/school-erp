<?php

namespace App\Console\Commands;

use App\Models\Fee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pre-Premium-UI corrective pass — Decision 2 follow-up.
 *
 * The audit that preceded this command found no code-provable identity for
 * the internal/test Fee records currently visible in Quick Registration
 * (e.g. "UAT_FINANCE_PHASE2_TEST") — they exist only as live UAT data,
 * created outside any migration/seeder in this repository. This command
 * therefore never guesses or hardcodes an id: it takes the exact Fee ids
 * to flag as explicit, required arguments, confirmed live against the
 * actual environment before it is ever run.
 *
 * Mirrors finance:uat-master-data-repair's own safety convention exactly:
 * default dry-run (prints exactly what would change, including any id
 * that doesn't exist or is already flagged), --apply required to write,
 * the whole write wrapped in one transaction. Marking is_test_data=true
 * NEVER deletes, deactivates, or otherwise mutates the Fee row itself —
 * historical invoice_items/tariff_adjustments/service_coverages
 * referencing it remain completely valid and queryable, unaffected by
 * this flag.
 */
class MarkTestFees extends Command
{
    protected $signature = 'finance:mark-test-fees
        {ids* : Exact Fee ids to flag as is_test_data=true. Must be confirmed live first — never guessed.}
        {--apply : Actually write the change. Default is dry-run — no writes.}';

    protected $description = 'UAT-only: flag specific, explicitly-identified Fee ids as is_test_data=true so Quick Registration excludes them — default dry-run, --apply required to write, never deletes/deactivates anything.';

    public function handle(): int
    {
        $ids = collect($this->argument('ids'))->map(fn ($id) => (int) $id)->unique()->values();
        $fees = Fee::withoutGlobalScopes()->whereIn('id', $ids)->get()->keyBy('id');

        $plan = $ids->map(function (int $id) use ($fees) {
            $fee = $fees->get($id);
            if (! $fee) {
                return ['id' => $id, 'action' => 'SKIP (no such Fee id)'];
            }
            if ($fee->is_test_data) {
                return ['id' => $id, 'action' => 'SKIP (already flagged)', 'name' => $fee->name_ru, 'category' => $fee->category];
            }

            return ['id' => $id, 'action' => 'FLAG is_test_data=true', 'name' => $fee->name_ru, 'category' => $fee->category, 'is_active' => $fee->is_active];
        });

        $this->table(['id', 'action', 'name', 'category', 'is_active'], $plan->map(fn (array $row) => [
            $row['id'], $row['action'], $row['name'] ?? '—', $row['category'] ?? '—', $row['is_active'] ?? '—',
        ]));

        $toFlag = $plan->filter(fn (array $row) => $row['action'] === 'FLAG is_test_data=true')->pluck('id');

        if ($toFlag->isEmpty()) {
            $this->components->info('Nothing to do — every id either does not exist or is already flagged.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->components->warn('Dry-run only — no changes written. Re-run with --apply to write the above.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($toFlag) {
            Fee::whereIn('id', $toFlag)->update(['is_test_data' => true]);
        });

        $this->components->info('Flagged '.$toFlag->count().' Fee id(s) as is_test_data=true. No row was deleted, deactivated, or otherwise altered.');

        return self::SUCCESS;
    }
}
