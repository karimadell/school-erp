<?php

namespace App\Observers;

use App\Models\Exam;
use App\Models\Quarter;
use App\Models\SchoolClass;

/**
 * Item 6 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, B6): populates the
 * academic_year_id/grade_id/stage_id snapshot on Exam at creation time
 * only. Registered in AppServiceProvider::boot() BEFORE
 * AcademicYearLockObserver for Exam, so the lock check always sees the
 * freshly-populated snapshot rather than falling back to quarter
 * derivation on every newly created exam.
 *
 * Only fires on creating() — never updating() — so an existing exam's
 * snapshot can never be touched by this observer, and never overwrites a
 * field that's already set (e.g. by a future caller that already knows
 * the year), only fills in what's still null.
 */
class ExamSnapshotObserver
{
    public function creating(Exam $exam): void
    {
        if ($exam->grade_id === null || $exam->stage_id === null) {
            $class = SchoolClass::find($exam->class_id);

            if ($exam->grade_id === null) {
                $exam->grade_id = $class?->grade_id;
            }

            if ($exam->stage_id === null) {
                $exam->stage_id = $class?->grade?->stage_id;
            }
        }

        if ($exam->academic_year_id === null && $exam->quarter_id !== null) {
            $exam->academic_year_id = Quarter::find($exam->quarter_id)?->resolveAcademicYear()?->id;
        }
    }
}
