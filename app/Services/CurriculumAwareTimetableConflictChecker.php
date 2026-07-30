<?php

namespace App\Services;

/**
 * Batch 1 / Curriculum Enforcement (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
 * identical to TimetableConflictChecker — same orchestration, same
 * TimetableConflictResult — configured with additional Curriculum,
 * TeacherAssignment and WorkingDay rules ahead of the four base conflict
 * rules (see the binding in AppServiceProvider). Exists as its own
 * bindable type so both timetable UIs can resolve this Curriculum-aware
 * rule set. As of Batch 10, TimetableController (#1, deprecated) also
 * resolves this binding instead of the unmodified TimetableConflictChecker
 * — every mutation route (store/update/move) is now enforced by the same
 * rule set as the canonical TimetableGrid.
 */
class CurriculumAwareTimetableConflictChecker extends TimetableConflictChecker
{
}
