<?php

namespace App\Support;

use App\Contracts\TimetableConflictRule;
use App\Models\Timetable;

/**
 * Batch 1 / Curriculum Enforcement (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
 * a class may not have more lessons of a given subject scheduled than
 * Curriculum.weekly_hours allows. Counts existing Timetable rows for
 * (class, subject) — excluding $slot->ignoreIds, the same mechanism the
 * base conflict rules use, so an in-place edit or a same-subject
 * drag-move (which doesn't change the total count) is never blocked by
 * its own existing occupancy.
 *
 * Independent of CurriculumSubjectRule (checked separately, defensively
 * — this rule doesn't rely on running after it, even though the
 * configured order in AppServiceProvider means it always does in
 * practice).
 */
class CurriculumWeeklyHoursRule implements TimetableConflictRule
{
    public function check(TimetableSlot $slot): ?string
    {
        $context = CurriculumContext::forClass($slot->classId);

        if ($context === null) {
            return 'timetable.grade_unresolvable';
        }

        $curriculum = $context->curriculumFor($slot->subjectId);

        if ($curriculum === null) {
            // No Curriculum row for this subject at all — CurriculumSubjectRule
            // is responsible for rejecting that case; nothing to enforce here.
            return null;
        }

        $scheduledCount = Timetable::where('class_id', $slot->classId)
            ->where('subject_id', $slot->subjectId)
            ->when($slot->ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $slot->ignoreIds))
            ->count();

        return $scheduledCount >= $curriculum->weekly_hours ? 'timetable.weekly_hours_exceeded' : null;
    }
}
