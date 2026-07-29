<?php

namespace App\Services;

use App\Contracts\HolidayCalendar;
use App\Models\Holiday;
use Carbon\CarbonInterface;

class DatabaseHolidayCalendar implements HolidayCalendar
{
    public function isHoliday(CarbonInterface $date): bool
    {
        // whereDate() (not a raw string where()) — the `date` cast persists
        // with a time component on some drivers (e.g. SQLite stores
        // "00:00:00"), so a plain string comparison against toDateString()
        // is driver-dependent and silently wrong on those.
        return Holiday::whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->exists();
    }
}
