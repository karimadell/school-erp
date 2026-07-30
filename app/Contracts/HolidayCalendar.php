<?php

namespace App\Contracts;

use Carbon\CarbonInterface;

/**
 * D1 Phase 2: the one seam any future date-aware scheduling logic must
 * call instead of querying the `holidays` table directly. Not yet
 * consulted by TimetableGrid::generateTimetable() — see App\Models\
 * Holiday's doc comment for why a weekly, date-less template cannot act
 * on this today. Swapping the storage or logic behind this interface
 * later needs no change at any call site.
 */
interface HolidayCalendar
{
    public function isHoliday(CarbonInterface $date): bool;
}
