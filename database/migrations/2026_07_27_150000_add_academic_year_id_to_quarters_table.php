<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B1: connect quarters to a specific academic year (Section 2: "terms
 * configurable per academic year"). Nullable — mirrors the same stopgap
 * pattern already used for enrollments.academic_year_id — since existing
 * quarter rows (if any) have no year association today. No existing data
 * is modified; the real quarters table was confirmed empty before writing
 * this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('quarters', 'academic_year_id')) {
            Schema::table('quarters', function (Blueprint $table) {
                $table->foreignId('academic_year_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quarters', 'academic_year_id')) {
            Schema::table('quarters', function (Blueprint $table) {
                $table->dropConstrainedForeignId('academic_year_id');
            });
        }
    }
};
