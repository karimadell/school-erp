<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1 Phase 2 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, row D1): the
 * last consumer of `subjects.hours_per_week`
 * (TimetableGrid::generateTimetable()) now reads weekly hours from
 * Curriculum.weekly_hours instead — safe to drop. D1 Phase 1 already
 * dropped the other dead hours column, `weekly_lessons`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('hours_per_week');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->integer('hours_per_week')->default(3);
        });
    }
};
