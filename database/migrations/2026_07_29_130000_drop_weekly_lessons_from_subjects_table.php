<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1 Phase 1 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, row D1):
 * `subjects.weekly_lessons` has never been read or written anywhere in the
 * codebase since it was added — dead from day one. Removed on its own,
 * ahead of the separate `hours_per_week` → `Curriculum.weekly_hours`
 * migration (D1 Phase 2), which still has a live consumer
 * (`TimetableGrid::generateTimetable()`) and requires grade/academic-year
 * scoping design first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('weekly_lessons');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->integer('weekly_lessons')->default(3);
        });
    }
};
