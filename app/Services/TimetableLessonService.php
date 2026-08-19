<?php

namespace App\Services;

use App\Models\Timetable;
use App\Support\TimetableSlot;

/**
 * Extracted from ClassResource\Pages\TimetableGrid::saveLesson() so the
 * dashboard timetable surface (App\Http\Controllers\Dashboard\ClassTimetableController)
 * and the Filament grid share one write path instead of each re-implementing
 * the conflict-check-then-persist sequence. Business rules themselves stay in
 * CurriculumAwareTimetableConflictChecker — this class only orchestrates.
 */
class TimetableLessonService
{
    public function __construct(
        private readonly CurriculumAwareTimetableConflictChecker $conflictChecker,
    ) {}

    /**
     * @return string|null translation key of the conflict, or null on success
     */
    public function save(int $classId, int $dayId, int $periodId, int $subjectId, int $teacherId): ?string
    {
        // The row already occupying this exact class+day+period (if any) is
        // the one about to be upserted — excluded from every rule so editing
        // a slot never conflicts with itself.
        $existingId = Timetable::where('class_id', $classId)
            ->where('day_id', $dayId)
            ->where('period_id', $periodId)
            ->value('id');

        $slot = new TimetableSlot(
            classId: $classId,
            dayId: $dayId,
            periodId: $periodId,
            teacherId: $teacherId,
            subjectId: $subjectId,
            ignoreIds: $existingId ? [$existingId] : [],
        );

        $result = $this->conflictChecker->check($slot);

        if ($result->hasConflict()) {
            return $result->first();
        }

        Timetable::updateOrCreate(
            ['class_id' => $classId, 'day_id' => $dayId, 'period_id' => $periodId],
            ['subject_id' => $subjectId, 'teacher_id' => $teacherId],
        );

        return null;
    }

    /**
     * Removes the lesson occupying a single class+day+period slot, if any.
     * A no-op (not an error) when the slot is already empty, so a stale
     * double-submit of the delete button stays safe.
     */
    public function destroy(int $classId, int $dayId, int $periodId): void
    {
        Timetable::where('class_id', $classId)
            ->where('day_id', $dayId)
            ->where('period_id', $periodId)
            ->delete();
    }
}
