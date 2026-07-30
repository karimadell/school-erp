<?php

namespace App\Support;

use App\Contracts\TimetableConflictRule;
use App\Models\Timetable;

/**
 * The teacher already has a lesson at this day + period, in any class.
 */
class TeacherConflictRule implements TimetableConflictRule
{
    public function check(TimetableSlot $slot): ?string
    {
        $exists = Timetable::where('teacher_id', $slot->teacherId)
            ->where('day_id', $slot->dayId)
            ->where('period_id', $slot->periodId)
            ->when($slot->ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $slot->ignoreIds))
            ->exists();

        return $exists ? 'timetable.teacher_conflict' : null;
    }
}
