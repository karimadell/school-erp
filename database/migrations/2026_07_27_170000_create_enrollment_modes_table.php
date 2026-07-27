<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 4: study mode (regular, distance_learning, extensible to more later
 * without a schema change) belongs to the Enrollment for a specific
 * AcademicYear, not permanently to Student. A lookup table — not an enum
 * column — so an admin can add a new mode by inserting a row, matching this
 * project's existing pattern for admin-configurable domain data (e.g. Fee's
 * name/category columns) rather than static UI chrome.
 *
 * Deliberately independent of Fee's tuition category constants (approved
 * policy): no column here references or is derived from Fee.category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_modes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ru');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('enrollment_mode_id')
                ->nullable()
                ->after('academic_year_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enrollment_mode_id');
        });

        Schema::dropIfExists('enrollment_modes');
    }
};
