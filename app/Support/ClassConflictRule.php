<?php

namespace App\Support;

use App\Contracts\TimetableConflictRule;
use App\Models\Timetable;

/**
 * The class already has a lesson at this day + period, regardless of
 * subject or teacher.
 */
class ClassConflictRule implements TimetableConflictRule
{
    public function check(TimetableSlot $slot): ?string
    {
        $exists = Timetable::where('class_id', $slot->classId)
            ->where('day_id', $slot->dayId)
            ->where('period_id', $slot->periodId)
            ->when($slot->ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $slot->ignoreIds))
            ->exists();

        return $exists ? 'timetable.class_conflict' : null;
    }
}
