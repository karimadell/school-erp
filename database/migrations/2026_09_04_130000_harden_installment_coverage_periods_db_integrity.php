<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finance V2, Phase 2D corrective pass #3 (HIGH 4 — coverage DB
 * integrity, direct-SQL-bypass-resistant).
 *
 * Keeps every existing protection unchanged (application-level row
 * locking in InstallmentCoveragePeriod::validateIntegrity(), and the
 * portable period_end >= period_start guard from corrective pass #2's
 * own 2026_09_03_120000 migration) and adds, targeted, DB-level-only
 * hardening for the two invariants that were previously enforced ONLY
 * at the Eloquent layer — a direct raw-SQL insert bypassing the model
 * entirely could previously violate either:
 *
 *   1. The period must lie within its own ServiceCoverage's coverage_start/
 *      coverage_end span.
 *   2. The installment's own invoice must match the ServiceCoverage's
 *      InvoiceItem's invoice (no cross-invoice, and by extension no
 *      cross-student, mapping).
 *
 * SQLite: extends this project's own established trigger pattern
 * (2026_09_03_120000) with subqueries joining installment_coverage_periods
 * -> service_coverages and -> invoice_installments/invoice_items, so a
 * raw INSERT that violates either invariant is rejected by SQLite itself,
 * not merely by the model layer.
 *
 * PostgreSQL: a genuine EXCLUDE constraint (via the btree_gist extension)
 * additionally prevents two OVERLAPPING periods for the same
 * service_coverage_id at the database level — a stronger, native
 * guarantee than the SQLite trigger/model-layer overlap check, and the
 * one invariant a portable CHECK/trigger can express particularly well
 * on Postgres. The same bounds/cross-invoice checks as SQLite are also
 * added via a trigger function, since Postgres CHECK constraints cannot
 * reference other tables. This entire branch is guarded behind a driver
 * check exactly like every other Postgres-specific migration in this
 * codebase (2026_08_23_010000's own addCheckConstraints() precedent) —
 * SQLite behavior is completely unaffected by it.
 *
 * MySQL: no additional DB-level constraint added here (targeted
 * hardening, not a full rewrite) — the existing application-level
 * row-locking + validateIntegrity() checks remain the enforcement there,
 * unchanged from corrective pass #2.
 *
 * Honesty note: the PostgreSQL branch below has NOT been executed
 * against a real PostgreSQL server in this sandboxed environment (no
 * `psql`, no Docker) — written carefully from documented PostgreSQL
 * EXCLUDE/trigger syntax, but unverified. The SQLite branch IS verified
 * (this project's own test suite runs on SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->installSqliteTriggers();

            return;
        }

        if ($driver === 'pgsql') {
            $this->installPostgresConstraints();
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS installment_coverage_periods_bounds_check_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS installment_coverage_periods_cross_invoice_check_insert');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE installment_coverage_periods DROP CONSTRAINT IF EXISTS installment_coverage_periods_no_overlap');
            DB::unprepared('DROP TRIGGER IF EXISTS installment_coverage_periods_bounds_and_invoice_check ON installment_coverage_periods');
            DB::unprepared('DROP FUNCTION IF EXISTS check_installment_coverage_period_bounds_and_invoice()');
        }
    }

    private function installSqliteTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS installment_coverage_periods_bounds_check_insert');
        DB::unprepared(
            'CREATE TRIGGER installment_coverage_periods_bounds_check_insert '
            .'BEFORE INSERT ON installment_coverage_periods '
            .'WHEN (SELECT COUNT(*) FROM service_coverages sc WHERE sc.id = NEW.service_coverage_id '
            .'  AND NEW.period_start >= sc.coverage_start AND NEW.period_end <= sc.coverage_end) = 0 '
            ."BEGIN SELECT RAISE(ABORT, 'period must lie within its ServiceCoverage own coverage span'); END"
        );

        DB::unprepared('DROP TRIGGER IF EXISTS installment_coverage_periods_cross_invoice_check_insert');
        DB::unprepared(
            'CREATE TRIGGER installment_coverage_periods_cross_invoice_check_insert '
            .'BEFORE INSERT ON installment_coverage_periods '
            .'WHEN ('
            .'  SELECT ii.invoice_id FROM invoice_installments ii WHERE ii.id = NEW.invoice_installment_id'
            .') != ('
            .'  SELECT inv_item.invoice_id FROM service_coverages sc '
            .'  JOIN invoice_items inv_item ON inv_item.id = sc.invoice_item_id '
            .'  WHERE sc.id = NEW.service_coverage_id'
            .') '
            ."BEGIN SELECT RAISE(ABORT, 'installment and coverage must belong to the same invoice'); END"
        );
    }

    private function installPostgresConstraints(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('ALTER TABLE installment_coverage_periods DROP CONSTRAINT IF EXISTS installment_coverage_periods_no_overlap');
        DB::statement(
            'ALTER TABLE installment_coverage_periods ADD CONSTRAINT installment_coverage_periods_no_overlap '
            .'EXCLUDE USING gist (service_coverage_id WITH =, daterange(period_start, period_end, \'[]\') WITH &&)'
        );

        DB::unprepared('DROP TRIGGER IF EXISTS installment_coverage_periods_bounds_and_invoice_check ON installment_coverage_periods');
        DB::unprepared('DROP FUNCTION IF EXISTS check_installment_coverage_period_bounds_and_invoice()');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION check_installment_coverage_period_bounds_and_invoice() RETURNS TRIGGER AS $$
            DECLARE
                cov_start date;
                cov_end date;
                installment_invoice_id bigint;
                coverage_invoice_id bigint;
            BEGIN
                SELECT coverage_start, coverage_end INTO cov_start, cov_end
                FROM service_coverages WHERE id = NEW.service_coverage_id;
                IF NEW.period_start < cov_start OR NEW.period_end > cov_end THEN
                    RAISE EXCEPTION 'period must lie within its ServiceCoverage own coverage span';
                END IF;

                SELECT invoice_id INTO installment_invoice_id
                FROM invoice_installments WHERE id = NEW.invoice_installment_id;
                SELECT inv_item.invoice_id INTO coverage_invoice_id
                FROM service_coverages sc
                JOIN invoice_items inv_item ON inv_item.id = sc.invoice_item_id
                WHERE sc.id = NEW.service_coverage_id;
                IF installment_invoice_id IS DISTINCT FROM coverage_invoice_id THEN
                    RAISE EXCEPTION 'installment and coverage must belong to the same invoice';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared(
            'CREATE TRIGGER installment_coverage_periods_bounds_and_invoice_check '
            .'BEFORE INSERT ON installment_coverage_periods '
            .'FOR EACH ROW EXECUTE FUNCTION check_installment_coverage_period_bounds_and_invoice()'
        );
    }
};
