<?php

namespace App\Services;

use App\Models\AcademicCalendar;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class AcademicCalendarService
{
    public function isTeachingDay(CarbonInterface|string $date, AcademicYear|int|null $academicYear = null): bool
    {
        $date = Carbon::parse($date)->startOfDay();
        $calendar = $this->calendarFor($date, $academicYear);

        if (! $calendar) {
            return false;
        }

        $events = $this->eventsFor($calendar, $date);
        $override = $events->firstWhere('type', CalendarEvent::TYPE_TEACHING_OVERRIDE);

        if ($override) {
            return $override->effect !== CalendarEvent::EFFECT_NON_TEACHING;
        }

        if ($events->contains(fn (CalendarEvent $event) => in_array($event->type, [
            CalendarEvent::TYPE_OFFICIAL_HOLIDAY,
            CalendarEvent::TYPE_SCHOOL_HOLIDAY,
        ], true) || $event->effect === CalendarEvent::EFFECT_NON_TEACHING
        )) {
            return false;
        }

        return ! in_array(strtolower($date->format('D')), $calendar->weekly_days_off, true);
    }

    public function bellScheduleFor(
        CarbonInterface|string $date,
        AcademicYear|int|null $academicYear = null,
        ?int $shift = null,
    ): ?int {
        $date = Carbon::parse($date)->startOfDay();
        $calendar = $this->calendarFor($date, $academicYear);

        if (! $calendar) {
            return null;
        }

        $override = $this->eventsFor($calendar, $date)
            ->first(fn (CalendarEvent $event) => $event->bell_schedule_id !== null
                && ($event->shift === null || $shift === null || $event->shift === $shift)
            );

        return $override?->bell_schedule_id ?? $calendar->default_bell_schedule_id;
    }

    public function calendarFor(CarbonInterface|string $date, AcademicYear|int|null $academicYear = null): ?AcademicCalendar
    {
        $date = Carbon::parse($date)->startOfDay();

        if ($academicYear instanceof AcademicYear) {
            if ($date->lt($academicYear->start_date) || $date->gt($academicYear->end_date)) {
                return null;
            }

            return $academicYear->academicCalendar;
        }

        if (is_int($academicYear)) {
            return AcademicCalendar::where('academic_year_id', $academicYear)
                ->whereHas('academicYear', fn ($query) => $query
                    ->whereDate('start_date', '<=', $date->toDateString())
                    ->whereDate('end_date', '>=', $date->toDateString()))
                ->first();
        }

        return AcademicCalendar::whereHas('academicYear', fn ($query) => $query
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString()))
            ->first();
    }

    private function eventsFor(AcademicCalendar $calendar, CarbonInterface $date)
    {
        return $calendar->events()
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->orderByDesc('id')
            ->get();
    }
}
