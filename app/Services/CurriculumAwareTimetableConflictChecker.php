<?php

namespace App\Services;

/**
 * Batch 1 / Curriculum Enforcement (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
 * identical to TimetableConflictChecker — same orchestration, same
 * TimetableConflictResult — configured with two additional Curriculum
 * rules ahead of the four base conflict rules (see the binding in
 * AppServiceProvider). Exists as its own bindable type so only the
 * canonical UI (TimetableGrid) resolves the Curriculum-aware rule set;
 * TimetableController (#1, deprecated, receives no new functionality
 * per the approved architecture) keeps resolving the unmodified
 * TimetableConflictChecker binding.
 */
class CurriculumAwareTimetableConflictChecker extends TimetableConflictChecker
{
}
