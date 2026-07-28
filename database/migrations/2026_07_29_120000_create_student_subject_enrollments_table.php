<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 11 / C2 (docs/IMPLEMENTATION_READINESS_ROADMAP.md): records that
 * a student has individually elected a specific Curriculum row —
 * elective / optional-enrichment subjects only; mandatory subjects
 * apply to the whole grade implicitly and need no per-student record
 * (enforced by the observer, not the schema). References curriculum_id
 * only — no duplicated subject_id/academic_year_id/grade_id columns,
 * that scope travels with the Curriculum FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_subject_enrollments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_id')->constrained('curricula')->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['student_id', 'curriculum_id'],
                'student_subject_enrollments_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_subject_enrollments');
    }
};
