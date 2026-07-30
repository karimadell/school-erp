<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 8: teachers.user_id never existed in any prior migration, yet
 * TeacherClasses and TeacherTimetable both query
 * Teacher::where('user_id', Auth::id()) — every teacher login currently
 * throws a SQL error on those two pages. This is the prerequisite fix:
 * without a way to resolve the logged-in User to their Teacher record,
 * no record-level scoping in this batch can work at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
