<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1 Phase 2: holiday infrastructure only (App\Contracts\HolidayCalendar,
 * App\Models\Holiday) — not yet consulted anywhere. A date range (not one
 * row per date) covers both a single-day public holiday (start == end)
 * and a multi-week vacation without either an unbounded row count or a
 * bespoke "vacation period" shape, reusing the same start/end convention
 * already used by AcademicYear and Quarter.
 *
 * IMPORTANT — see App\Models\Holiday's doc comment: TimetableGrid::
 * generateTimetable() produces a perpetual weekly template keyed only on
 * day-of-week + period, with no calendar date of its own, so this table
 * cannot yet exclude anything from a generated timetable. It exists so a
 * future date-based generation feature (Academic Calendar module) needs
 * no schema or interface redesign — only a new call site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();

            $table->date('start_date');
            $table->date('end_date');

            $table->enum('type', [
                'public_holiday',
                'school_specific',
                'mid_year_vacation',
                'winter_vacation',
                'spring_vacation',
                'summer_vacation',
                'emergency_closure',
            ]);

            $table->string('name');

            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
