<?php

namespace App\Support;

/**
 * The candidate (class, day, period, teacher, subject, room) a timetable
 * write is being checked against. $ignoreIds excludes specific existing
 * Timetable rows from every rule's search — the row being updated on a
 * plain edit, or both rows involved in TimetableGrid's drag-to-swap move
 * (the dragged lesson and the lesson currently occupying the target
 * cell), so an in-place edit or swap is never flagged as conflicting
 * with itself.
 */
final class TimetableSlot
{
    /** @param int[] $ignoreIds */
    public function __construct(
        public readonly int $classId,
        public readonly int $dayId,
        public readonly int $periodId,
        public readonly int $teacherId,
        public readonly int $subjectId,
        public readonly ?string $room = null,
        public readonly array $ignoreIds = [],
    ) {}
}
