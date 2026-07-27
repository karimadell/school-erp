<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The unique index on (student_id, subject_id, exam_id, quarter_id) does not
 * prevent duplicate rows when quarter_id is NULL, because both MySQL and
 * SQLite treat NULL as distinct from any other NULL for uniqueness purposes.
 * updateOrCreate() relies on that index to stop duplicate grade rows, so
 * quarterless grades were silently able to duplicate.
 *
 * Fix: add a generated column that substitutes a sentinel (0, which no real
 * quarter can ever be since ids start at 1) for NULL, and enforce uniqueness
 * on that column instead. No existing column, row, or value is touched.
 *
 * Uses VIRTUAL rather than STORED: on MySQL 8+, adding a STORED generated
 * column to this table fails with "Error 1215: Cannot add foreign key
 * constraint" (verified against a live MySQL 9.6 instance) because a STORED
 * column forces a table rebuild that MySQL cannot reconcile with this
 * table's existing foreign keys. VIRTUAL only needs metadata changes plus a
 * secondary index (supported since MySQL 5.7.6) and was verified to work
 * against both a live MySQL instance and SQLite (bundled 3.51.2, generated
 * columns require 3.31+) through Laravel's own Schema Builder.
 *
 * Index drop/create order matters: `unique_student_grade` is the only index
 * with `student_id` as its leftmost column, so InnoDB is silently relying on
 * it to satisfy the `student_grades_student_id_foreign` key. Dropping it
 * before another qualifying index exists fails with "Error 1553: Cannot
 * drop index ... needed in a foreign key constraint" (also verified live).
 * The new index also leads with `student_id`, so it must be created first;
 * only then can the old index be safely dropped. down() mirrors this in
 * reverse for the same reason.
 */
return new class extends Migration
{
    private string $oldIndex = 'unique_student_grade';

    private string $newIndex = 'student_grades_student_subject_exam_quarter_key_unique';

    public function up(): void
    {
        if (!Schema::hasColumn('student_grades', 'quarter_key')) {
            Schema::table('student_grades', function (Blueprint $table) {
                $table->unsignedBigInteger('quarter_key')
                    ->virtualAs('COALESCE(quarter_id, 0)')
                    ->after('quarter_id');
            });
        }

        if (!Schema::hasIndex('student_grades', $this->newIndex)) {
            Schema::table('student_grades', function (Blueprint $table) {
                $table->unique(['student_id', 'subject_id', 'exam_id', 'quarter_key'], $this->newIndex);
            });
        }

        if (Schema::hasIndex('student_grades', $this->oldIndex)) {
            Schema::table('student_grades', function (Blueprint $table) {
                $table->dropUnique($this->oldIndex);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasIndex('student_grades', $this->oldIndex)) {
            DB::statement('CREATE UNIQUE INDEX ' . $this->oldIndex . ' ON student_grades (student_id, subject_id, exam_id, quarter_id)');
        }

        if (Schema::hasIndex('student_grades', $this->newIndex)) {
            Schema::table('student_grades', function (Blueprint $table) {
                $table->dropUnique($this->newIndex);
            });
        }

        if (Schema::hasColumn('student_grades', 'quarter_key')) {
            Schema::table('student_grades', function (Blueprint $table) {
                $table->dropColumn('quarter_key');
            });
        }
    }
};
