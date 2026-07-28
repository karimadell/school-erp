<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item 3 / B1 (docs/IMPLEMENTATION_READINESS_ROADMAP.md): quarters.academic_year_id
 * was added nullable (2026_07_27_150000) as a stopgap. It is now required,
 * matching the same pattern already applied to enrollments.academic_year_id
 * (2026_07_27_160000).
 *
 * Restrict-on-delete replaces the previous set-null-on-delete FK action —
 * a NOT NULL column cannot be nulled by a delete, and null was never an
 * acceptable historical state for this column going forward. Deleting an
 * AcademicYear that still has quarters is now blocked rather than silently
 * orphaning them.
 *
 * Pre-flight check performed against the local development database only
 * (school_erp @ 127.0.0.1): 0 quarters, 0 with a null academic_year_id, so
 * this runs as a clean no-backfill change there. This has NOT been verified
 * against the real production database. The guard below refuses to proceed
 * if it finds any null academic_year_id row at migration time, in whatever
 * database it is actually run against — but that guard is a safety net, not
 * a substitute for running the same manual pre-flight check against
 * production before this migration is deployed there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $nullCount = DB::table('quarters')->whereNull('academic_year_id')->count();

        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Refusing to run: {$nullCount} quarters row(s) have a NULL academic_year_id. ".
                'These cannot be safely auto-mapped to an AcademicYear (see docs/IMPLEMENTATION_READINESS_ROADMAP.md, '.
                'item B1) — resolve them with a manual, reviewed backfill before running this migration.'
            );
        }

        Schema::table('quarters', function (Blueprint $table) {
            $table->dropForeign(['academic_year_id']);
        });

        Schema::table('quarters', function (Blueprint $table) {
            $table->foreignId('academic_year_id')->nullable(false)->change();
        });

        Schema::table('quarters', function (Blueprint $table) {
            $table->foreign('academic_year_id')
                ->references('id')->on('academic_years')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quarters', function (Blueprint $table) {
            $table->dropForeign(['academic_year_id']);
        });

        Schema::table('quarters', function (Blueprint $table) {
            $table->foreignId('academic_year_id')->nullable()->change();
        });

        Schema::table('quarters', function (Blueprint $table) {
            $table->foreign('academic_year_id')
                ->references('id')->on('academic_years')
                ->nullOnDelete();
        });
    }
};
