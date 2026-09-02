<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        match (DB::connection()->getDriverName()) {
            'sqlite' => $this->sqliteUp(),
            'pgsql' => $this->postgresUp(),
            default => null,
        };
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            foreach (['icp_integrity_insert_v4', 'icp_integrity_update_v4', 'icp_overlap_insert_v4', 'icp_overlap_update_v4', 'service_coverage_owner_insert_v4', 'service_coverage_owner_update_v4'] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        } elseif (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS service_coverage_owner_check_v4 ON service_coverages');
            DB::unprepared('DROP FUNCTION IF EXISTS check_service_coverage_owner_v4()');
            DB::unprepared('DROP TRIGGER IF EXISTS installment_coverage_periods_bounds_and_invoice_check ON installment_coverage_periods');
            DB::unprepared("CREATE TRIGGER installment_coverage_periods_bounds_and_invoice_check BEFORE INSERT ON installment_coverage_periods FOR EACH ROW EXECUTE FUNCTION check_installment_coverage_period_bounds_and_invoice()");
        }
    }

    private function sqliteUp(): void
    {
        foreach (['icp_integrity_insert_v4', 'icp_integrity_update_v4', 'icp_overlap_insert_v4', 'icp_overlap_update_v4', 'service_coverage_owner_insert_v4', 'service_coverage_owner_update_v4'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }

        foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $event => $suffix) {
            DB::unprepared("CREATE TRIGGER icp_integrity_{$suffix}_v4 BEFORE {$event} ON installment_coverage_periods
                WHEN NEW.period_end < NEW.period_start
                  OR NOT EXISTS (SELECT 1 FROM service_coverages sc WHERE sc.id = NEW.service_coverage_id AND NEW.period_start >= sc.coverage_start AND NEW.period_end <= sc.coverage_end)
                  OR NOT EXISTS (
                    SELECT 1 FROM invoice_installments ii
                    JOIN invoices inv ON inv.id = ii.invoice_id
                    JOIN service_coverages sc ON sc.id = NEW.service_coverage_id
                    JOIN invoice_items item ON item.id = sc.invoice_item_id
                    WHERE ii.id = NEW.invoice_installment_id
                      AND item.invoice_id = ii.invoice_id
                      AND sc.student_id = inv.student_id
                  )
                BEGIN SELECT RAISE(ABORT, 'invalid installment coverage period integrity'); END");

            $exclude = $event === 'UPDATE' ? 'AND existing.id != OLD.id' : '';
            DB::unprepared("CREATE TRIGGER icp_overlap_{$suffix}_v4 BEFORE {$event} ON installment_coverage_periods
                WHEN EXISTS (
                    SELECT 1 FROM installment_coverage_periods existing
                    WHERE existing.service_coverage_id = NEW.service_coverage_id
                      {$exclude}
                      AND existing.period_start <= NEW.period_end
                      AND existing.period_end >= NEW.period_start
                )
                BEGIN SELECT RAISE(ABORT, 'overlapping installment coverage period'); END");

            DB::unprepared("CREATE TRIGGER service_coverage_owner_{$suffix}_v4 BEFORE {$event} ON service_coverages
                WHEN NOT EXISTS (
                    SELECT 1 FROM invoice_items item
                    JOIN invoices inv ON inv.id = item.invoice_id
                    WHERE item.id = NEW.invoice_item_id AND inv.student_id = NEW.student_id
                )
                BEGIN SELECT RAISE(ABORT, 'service coverage student must match invoice student'); END");
        }
    }

    private function postgresUp(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_installment_coverage_period_bounds_and_invoice() RETURNS TRIGGER AS $$
            DECLARE cov_start date; cov_end date; installment_invoice_id bigint; coverage_invoice_id bigint; invoice_student_id bigint; coverage_student_id bigint;
            BEGIN
                SELECT coverage_start, coverage_end, student_id INTO cov_start, cov_end, coverage_student_id FROM service_coverages WHERE id = NEW.service_coverage_id;
                IF NEW.period_start < cov_start OR NEW.period_end > cov_end THEN
                    RAISE EXCEPTION 'period must lie within its ServiceCoverage own coverage span';
                END IF;
                SELECT invoice_id INTO installment_invoice_id FROM invoice_installments WHERE id = NEW.invoice_installment_id;
                SELECT item.invoice_id INTO coverage_invoice_id FROM service_coverages sc JOIN invoice_items item ON item.id = sc.invoice_item_id WHERE sc.id = NEW.service_coverage_id;
                SELECT student_id INTO invoice_student_id FROM invoices WHERE id = installment_invoice_id;
                IF installment_invoice_id IS DISTINCT FROM coverage_invoice_id THEN
                    RAISE EXCEPTION 'installment and coverage must belong to the same invoice';
                END IF;
                IF coverage_student_id IS DISTINCT FROM invoice_student_id THEN
                    RAISE EXCEPTION 'service coverage student must match invoice student';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::unprepared('DROP TRIGGER IF EXISTS installment_coverage_periods_bounds_and_invoice_check ON installment_coverage_periods');
        DB::unprepared('CREATE TRIGGER installment_coverage_periods_bounds_and_invoice_check BEFORE INSERT OR UPDATE ON installment_coverage_periods FOR EACH ROW EXECUTE FUNCTION check_installment_coverage_period_bounds_and_invoice()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_service_coverage_owner_v4() RETURNS TRIGGER AS $$
            DECLARE invoice_student_id bigint;
            BEGIN
                SELECT inv.student_id INTO invoice_student_id FROM invoice_items item JOIN invoices inv ON inv.id = item.invoice_id WHERE item.id = NEW.invoice_item_id;
                IF invoice_student_id IS DISTINCT FROM NEW.student_id THEN
                    RAISE EXCEPTION 'service coverage student must match invoice student';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::unprepared('DROP TRIGGER IF EXISTS service_coverage_owner_check_v4 ON service_coverages');
        DB::unprepared('CREATE TRIGGER service_coverage_owner_check_v4 BEFORE INSERT OR UPDATE ON service_coverages FOR EACH ROW EXECUTE FUNCTION check_service_coverage_owner_v4()');
    }
};
