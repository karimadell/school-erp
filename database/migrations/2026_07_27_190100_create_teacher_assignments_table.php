<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 8: TeacherAssignment (teacher x class x subject x academic year)
 * is the authorization source of truth for the Teacher Portal — distinct
 * from teacher_subject (qualification: "can teach this subject at all")
 * and from timetables (a flat, year-agnostic scheduling template with no
 * academic_year_id). The two are intentionally kept independent per
 * approved policy: no FK/validation dependency on teacher_subject.
 *
 * Backfill (approved policy): one-time only, generated from existing
 * timetables rows stamped with the currently active academic year, so
 * teachers already scheduled via Timetable don't lose Teacher Portal
 * access on day one. Uses DB facade (not Eloquent) to avoid model
 * side effects, and insertOrIgnore to collapse the many day/period rows
 * a single class+subject+teacher combination has in timetables down to
 * one assignment row per the unique constraint below. Skipped entirely
 * if no academic year is currently active — there is no valid year to
 * stamp backfilled rows with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['teacher_id', 'class_id', 'subject_id', 'academic_year_id'],
                'teacher_assignments_unique'
            );
        });

        $this->backfillFromTimetables();
    }

    protected function backfillFromTimetables(): void
    {
        $activeYearId = DB::table('academic_years')->where('is_active', true)->value('id');

        if (! $activeYearId) {
            return;
        }

        $now = now();

        $rows = DB::table('timetables')
            ->select('teacher_id', 'class_id', 'subject_id')
            ->distinct()
            ->get()
            ->map(fn ($row) => [
                'teacher_id' => $row->teacher_id,
                'class_id' => $row->class_id,
                'subject_id' => $row->subject_id,
                'academic_year_id' => $activeYearId,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if (empty($rows)) {
            return;
        }

        DB::table('teacher_assignments')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_assignments');
    }
};
