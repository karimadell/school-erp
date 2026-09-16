<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Collection foundation (PR B) — purely additive links from the
 * two existing accounting tables to the FinanceCollection that produced
 * them. Nullable everywhere: every existing invoice/invoice_payment row,
 * and every future one created by a caller other than
 * FinanceCollectionService (Quick Registration, Classic Invoice, Mass
 * Billing, Charge & Collect, ...), keeps finance_collection_id = null and
 * is completely unaffected — no existing identifier, semantic, or
 * behavior changes.
 *
 * SQLite corrective note: adding an FK-constrained column forces SQLite's
 * schema builder to rebuild the `invoices` table (copy to a temp table,
 * drop, rename back) — see 2026_09_04_140000_harden_coverage_integrity_on_
 * update.php's own sqliteUp() for the exact same class of problem. Two
 * distinct trigger groups must survive that rebuild intact:
 *  - icp_integrity_*_v4/service_coverage_owner_*_v4 live on OTHER tables
 *    (installment_coverage_periods/service_coverages) but reference
 *    `invoices` by name inside their own body, which makes SQLite's
 *    mid-rebuild rename of `invoices` fail while they still exist.
 *  - invoices_status_check_insert/_update live directly ON `invoices`
 *    (2026_05_15_014549_update_invoice_status_enum_values.php's own
 *    replaceSqliteStatusTriggers()) — SQLite's copy-and-rename rebuild
 *    strategy drops every trigger defined on the table being rebuilt, so
 *    these are silently lost unless explicitly recreated afterward too.
 * Same fix for both: drop immediately before the rebuild, recreate the
 * exact same definitions immediately after — purely a SQLite schema-
 * change mechanic, never a behavior change (every trigger's own logic is
 * byte-identical before and after this migration).
 */
return new class extends Migration
{
    private const SQLITE_TRIGGERS = [
        'icp_integrity_insert_v4', 'icp_integrity_update_v4',
        'icp_overlap_insert_v4', 'icp_overlap_update_v4',
        'service_coverage_owner_insert_v4', 'service_coverage_owner_update_v4',
    ];

    public function up(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        if ($isSqlite) {
            $this->dropSqliteTriggers();
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('finance_collection_id')->nullable()->after('id')
                ->constrained('finance_collections')->nullOnDelete();
        });

        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->foreignId('finance_collection_id')->nullable()->after('id')
                ->constrained('finance_collections')->nullOnDelete();
        });

        if ($isSqlite) {
            $this->createSqliteTriggers();
        }
    }

    public function down(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        if ($isSqlite) {
            $this->dropSqliteTriggers();
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finance_collection_id');
        });

        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finance_collection_id');
        });

        if ($isSqlite) {
            $this->createSqliteTriggers();
        }
    }

    private function dropSqliteTriggers(): void
    {
        foreach (self::SQLITE_TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS invoices_status_check_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS invoices_status_check_update');
    }

    /**
     * Verbatim re-creation of 2026_09_04_140000_harden_coverage_integrity_
     * on_update.php's own sqliteUp() trigger bodies — never redefine these
     * independently, or the two migrations could silently drift apart.
     */
    private function createSqliteTriggers(): void
    {
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

        // Verbatim re-creation of 2026_05_15_014549_update_invoice_status_
        // enum_values.php's own replaceSqliteStatusTriggers(['unpaid',
        // 'partial', 'paid', 'cancelled']) — never redefine independently.
        DB::unprepared("CREATE TRIGGER invoices_status_check_insert BEFORE INSERT ON invoices WHEN NEW.status NOT IN ('unpaid', 'partial', 'paid', 'cancelled') BEGIN SELECT RAISE(ABORT, 'invalid invoices.status'); END");
        DB::unprepared("CREATE TRIGGER invoices_status_check_update BEFORE UPDATE OF status ON invoices WHEN NEW.status NOT IN ('unpaid', 'partial', 'paid', 'cancelled') BEGIN SELECT RAISE(ABORT, 'invalid invoices.status'); END");
    }
};
