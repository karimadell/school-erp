<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finance V2, Phase 2D corrective pass #2 (HIGH 6 — coverage-period
 * integrity and concurrency). InstallmentCoveragePeriod::validateIntegrity()
 * already enforces period_end >= period_start at the model layer (every
 * write in this codebase goes through Eloquent) — this adds the SAME
 * invariant as a genuine DB-level constraint too, defense-in-depth for
 * any write path that might bypass the model (a raw query, a future
 * migration backfill, direct DB access).
 *
 * Follows this project's own established portable-CHECK-constraint
 * pattern (2026_08_15_000000_restore_sqlite_finance_value_guards.php):
 * SQLite has no native CHECK-after-the-fact support, so a trigger stands
 * in for it there; MySQL (8.0.16+) and PostgreSQL get a real CHECK
 * constraint. On an older MySQL that silently ignores CHECK constraints,
 * this becomes a harmless no-op — the model-layer guard remains the
 * actual enforcement in that case, exactly as it already was before this
 * migration; this is additive defense-in-depth, never the sole guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS installment_coverage_periods_order_check_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS installment_coverage_periods_order_check_update');
            DB::unprepared(
                'CREATE TRIGGER installment_coverage_periods_order_check_insert '
                .'BEFORE INSERT ON installment_coverage_periods '
                .'WHEN NEW.period_end < NEW.period_start '
                ."BEGIN SELECT RAISE(ABORT, 'period_end must not be before period_start'); END"
            );
            DB::unprepared(
                'CREATE TRIGGER installment_coverage_periods_order_check_update '
                .'BEFORE UPDATE OF period_start, period_end ON installment_coverage_periods '
                .'WHEN NEW.period_end < NEW.period_start '
                ."BEGIN SELECT RAISE(ABORT, 'period_end must not be before period_start'); END"
            );

            return;
        }

        if ($driver === 'mysql') {
            // Silently a no-op on MySQL < 8.0.16 (CHECK constraints are
            // parsed but not enforced there) — never a hard failure for
            // an older server, and never the sole enforcement either way.
            DB::statement('ALTER TABLE installment_coverage_periods ADD CONSTRAINT installment_coverage_periods_order_check CHECK (period_end >= period_start)');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE installment_coverage_periods ADD CONSTRAINT installment_coverage_periods_order_check CHECK (period_end >= period_start)');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS installment_coverage_periods_order_check_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS installment_coverage_periods_order_check_update');

            return;
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE installment_coverage_periods DROP CHECK installment_coverage_periods_order_check');
        }
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE installment_coverage_periods DROP CONSTRAINT installment_coverage_periods_order_check');
        }
    }
};
