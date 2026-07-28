<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 8: student_grades.exam_id has been NOT NULL since the original
 * create_student_grades_table migration. A later migration intended to
 * add it as nullable, but its `if (!Schema::hasColumn(...))` guard
 * silently skipped that column because it already existed — so the
 * NOT NULL constraint was never actually relaxed. This blocks the
 * quarter-only grading flow entirely: TeacherGrades::saveGrades() (and
 * any other quarter-based, non-exam grade entry) has never been able to
 * insert a row, since it has no exam_id to supply. Discovered while
 * writing tests for this batch's TeacherGrades scoping — same category
 * as the invoices.paid_at fix in Batch 5: a pre-existing bug that blocks
 * writing valid fixtures for the feature actually being tested.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_grades', function (Blueprint $table) {
            $table->foreignId('exam_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('student_grades', function (Blueprint $table) {
            $table->foreignId('exam_id')->nullable(false)->change();
        });
    }
};
