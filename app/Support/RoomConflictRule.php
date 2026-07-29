<?php

namespace App\Support;

use App\Contracts\TimetableConflictRule;
use App\Models\Timetable;

/**
 * The room already has a lesson at this day + period. Skipped entirely
 * when no room is set on the slot — matching the pre-existing behavior
 * this rule replaces (an unset room was never checked).
 */
class RoomConflictRule implements TimetableConflictRule
{
    public function check(TimetableSlot $slot): ?string
    {
        if (empty($slot->room)) {
            return null;
        }

        $exists = Timetable::where('room', $slot->room)
            ->where('day_id', $slot->dayId)
            ->where('period_id', $slot->periodId)
            ->when($slot->ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $slot->ignoreIds))
            ->exists();

        return $exists ? 'timetable.room_conflict' : null;
    }
}
