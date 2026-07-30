<?php

namespace App\Support;

use App\Models\TimetableSetting;
use Illuminate\Support\Collection;

/**
 * D1 Phase 2: the single place that knows which Day rows are currently
 * non-working. Reads TimetableSetting (default: Friday + Saturday, per
 * that model's own migration) rather than any weekday literal hard-coded
 * here or in TimetableGrid. A future School Settings module only needs
 * to change TimetableSetting's stored value — this class and its one
 * caller (TimetableGrid::generateTimetable()) never need to change.
 */
class WorkingDays
{
    public function nonWorkingDayCodes(): array
    {
        return TimetableSetting::current()->non_working_days;
    }

    public function workingDays(Collection $days): Collection
    {
        $nonWorking = $this->nonWorkingDayCodes();

        return $days->reject(fn ($day) => in_array($day->code, $nonWorking, true));
    }
}
