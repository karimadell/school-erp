<?php

namespace App\Support;

use App\Contracts\TimetableConflictRule;
use App\Models\Day;

/**
 * Batch 3 / Working Days Enforcement (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
 * the slot's day must be a configured working day. Reuses WorkingDays/
 * TimetableSetting exactly as they already exist for generateTimetable()
 * — no new Working Days logic. Runs first in
 * CurriculumAwareTimetableConflictChecker's rule list: a non-working day
 * is an invalid target regardless of subject/teacher/conflict validity.
 */
class WorkingDayRule implements TimetableConflictRule
{
    public function check(TimetableSlot $slot): ?string
    {
        $day = Day::find($slot->dayId);
        $nonWorking = app(WorkingDays::class)->nonWorkingDayCodes();

        if ($day !== null && in_array($day->code, $nonWorking, true)) {
            return 'timetable.non_working_day';
        }

        return null;
    }
}
