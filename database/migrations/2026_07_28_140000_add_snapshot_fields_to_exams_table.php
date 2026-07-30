<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 6 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, B6): Exam.class_id ->
 * SchoolClass.grade_id -> Grade.stage_id are all mutable (confirmed —
 * ClassController::update()/GradeController::update() both allow
 * reassignment), and Exam.quarter_id -> Quarter.academic_year_id is only
 * a transitive derivation with nullOnDelete. Either can silently and
 * retroactively change which grade/stage/year a historical exam implies
 * it belonged to. These three nullable snapshot columns close that,
 * mirroring the write-once discipline Enrollment already applies to its
 * own stage_id/grade_id/class_id.
 *
 * All three nullable — no backfill bundled into this migration (approved
 * decision: any backfill for pre-existing rows is a separate, explicit,
 * later step, never a side effect of this schema change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->foreignId('academic_year_id')
                ->nullable()
                ->after('quarter_id')
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('grade_id')
                ->nullable()
                ->after('academic_year_id')
                ->constrained('grades')
                ->nullOnDelete();

            $table->foreignId('stage_id')
                ->nullable()
                ->after('grade_id')
                ->constrained('stages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stage_id');
            $table->dropConstrainedForeignId('grade_id');
            $table->dropConstrainedForeignId('academic_year_id');
        });
    }
};
