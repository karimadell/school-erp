<?php

namespace App\Support;

use App\Contracts\TimetableConflictRule;

/**
 * Batch 1 / Curriculum Enforcement (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
 * the slot's subject must be part of the active year's Curriculum for the
 * class's grade. Used only by the canonical UI's conflict checker
 * (App\Services\CurriculumAwareTimetableConflictChecker) — never added to
 * the base TimetableConflictChecker, which TimetableController (#1,
 * deprecated) still resolves unmodified.
 */
class CurriculumSubjectRule implements TimetableConflictRule
{
    public function check(TimetableSlot $slot): ?string
    {
        $context = CurriculumContext::forClass($slot->classId);

        if ($context === null) {
            return 'timetable.grade_unresolvable';
        }

        return $context->curriculumFor($slot->subjectId) !== null ? null : 'timetable.subject_not_in_curriculum';
    }
}
