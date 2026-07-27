<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 3 (B5): academic_year_id was made nullable as a Sprint 0 stopgap
 * (2026_07_26_010614) because EnrollmentController treated it as optional.
 * It is now required on every enrollment, restoring the original NOT NULL
 * intent and making the pre-existing unique(student_id, academic_year_id)
 * constraint (present since the very first enrollments migration) fully
 * effective — MySQL/SQLite both treat NULL as never equal to NULL in a
 * unique index, so null-year duplicates could previously slip through.
 *
 * Verified against the real dev database before writing this: the
 * enrollments table is currently empty, so no backfill decision is needed.
 * class_room_id is untouched — out of scope for this batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('academic_year_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('academic_year_id')->nullable()->change();
        });
    }
};
