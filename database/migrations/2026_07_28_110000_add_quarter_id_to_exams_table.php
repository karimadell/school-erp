<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 9: TeacherGrades becomes Exam-linked (approved policy — no
 * permanent quarter-only path). "Quarter context must be derived through
 * the related Exam and its academic relationships" requires exams to
 * carry a quarter_id, which never existed before (exams only had name/
 * subject_id/class_id/exam_date/max_score). Nullable per approved
 * policy: Section 4 of OPEN_POLICY_DECISIONS.md leaves open whether a
 * final exam belongs to any specific quarter at all — forcing NOT NULL
 * here would preempt that unresolved decision. Academic year is derived
 * transitively via quarter_id -> quarters.academic_year_id, matching the
 * existing Quarter/AcademicYear relationship rather than duplicating it
 * directly on Exam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->foreignId('quarter_id')
                ->nullable()
                ->after('class_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quarter_id');
        });
    }
};
