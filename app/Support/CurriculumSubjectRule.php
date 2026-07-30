<?php

namespace App\Support;

use App\Contracts\TimetableConflictRule;

/**
 * Batch 1 / Curriculum Enforcement (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
 * the slot's subject must be part of the active year's Curriculum for the
 * class's grade. Used by App\Services\CurriculumAwareTimetableConflictChecker
 * — never added to the base TimetableConflictChecker (Batch 13: that
 * binding, and the legacy TimetableController that was its only caller,
 * have both been removed).
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
