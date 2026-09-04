<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Services\AcademicCalendarService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FoodBillableDayCalculator
{
    public const RULE_VERSION = 'academic-calendar-teaching-day-v1';

    public function __construct(private AcademicCalendarService $calendar) {}

    /**
     * @return array{coverage_start:string,coverage_end:string,billable_dates:array<int,string>,billable_day_count:int,excluded_day_count:int,rule_version:string,calendar_id:int}
     */
    public function calculate(AcademicYear $year, string $startDate, string $endDate, bool $requireBillableDay = true): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        if ($end->lt($start) || $start->lt($year->start_date) || $end->gt($year->end_date)) {
            throw ValidationException::withMessages(['food_coverage_end_month' => 'Период питания должен находиться внутри выбранного учебного года.']);
        }

        $academicCalendar = $this->calendar->calendarFor($start, $year);
        if (! $academicCalendar || $this->calendar->calendarFor($end, $year)?->id !== $academicCalendar->id) {
            throw ValidationException::withMessages(['food_coverage_start_month' => 'Для выбранного периода не настроен единый учебный календарь.']);
        }

        $billable = [];
        $excluded = 0;
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if ($this->calendar->isTeachingDay($date, $year)) {
                $billable[] = $date->toDateString();
            } else {
                $excluded++;
            }
        }

        if ($requireBillableDay && $billable === []) {
            throw ValidationException::withMessages(['food_coverage_end_month' => 'Выбранный период не содержит ни одного учебного дня с услугой питания.']);
        }

        return [
            'coverage_start' => $start->toDateString(),
            'coverage_end' => $end->toDateString(),
            'billable_dates' => $billable,
            'billable_day_count' => count($billable),
            'excluded_day_count' => $excluded,
            'rule_version' => self::RULE_VERSION,
            'calendar_id' => $academicCalendar->id,
        ];
    }

    /**
     * "N teaching days starting from a date" — a genuinely different mode
     * from calculate(): the caller supplies a COUNT, not an end date, and
     * this walks forward from $startDate accumulating only real teaching
     * days (per AcademicCalendarService::isTeachingDay() — weekends,
     * holidays, and closures are skipped; a teaching_override reopening a
     * normally-closed day counts) until exactly $count have been reached.
     * coverage_end is the date of the Nth billable day itself — never
     * padded further. Fails closed (ValidationException) if the academic
     * year's own bounds are exhausted before N teaching days are found, or
     * if the walk crosses into a different AcademicCalendar mid-range.
     *
     * Returns the EXACT SAME shape calculate() returns — every downstream
     * consumer (pricing, ServiceCoverage/InstallmentCoveragePeriod
     * creation, TariffAdjustmentService) stays mode-agnostic once a range
     * is resolved, regardless of which of the two methods produced it.
     *
     * @return array{coverage_start:string,coverage_end:string,billable_dates:array<int,string>,billable_day_count:int,excluded_day_count:int,rule_version:string,calendar_id:int}
     */
    public function resolveForwardFromCount(AcademicYear $year, Carbon|string $startDate, int $count): array
    {
        if ($count < 1) {
            throw ValidationException::withMessages(['food_day_count' => 'Количество дней питания должно быть больше нуля.']);
        }

        $start = Carbon::parse($startDate)->startOfDay();
        if ($start->lt($year->start_date) || $start->gt($year->end_date)) {
            throw ValidationException::withMessages(['food_start_date' => 'Дата начала питания должна находиться внутри выбранного учебного года.']);
        }

        $academicCalendar = $this->calendar->calendarFor($start, $year);
        if (! $academicCalendar) {
            throw ValidationException::withMessages(['food_start_date' => 'Для выбранной даты не настроен учебный календарь.']);
        }

        $billable = [];
        $excluded = 0;
        $date = $start->copy();
        while (count($billable) < $count) {
            if ($date->gt($year->end_date)) {
                throw ValidationException::withMessages(['food_day_count' => 'Запрошенное количество учебных дней питания выходит за пределы учебного года.']);
            }
            $calendarForDate = $this->calendar->calendarFor($date, $year);
            if (! $calendarForDate || $calendarForDate->id !== $academicCalendar->id) {
                throw ValidationException::withMessages(['food_day_count' => 'Период питания должен находиться внутри одного учебного календаря.']);
            }
            if ($this->calendar->isTeachingDay($date, $year)) {
                $billable[] = $date->toDateString();
            } else {
                $excluded++;
            }
            $date->addDay();
        }

        return [
            'coverage_start' => $start->toDateString(),
            'coverage_end' => (string) end($billable),
            'billable_dates' => $billable,
            'billable_day_count' => count($billable),
            'excluded_day_count' => $excluded,
            'rule_version' => self::RULE_VERSION,
            'calendar_id' => $academicCalendar->id,
        ];
    }

    /**
     * The single canonical entry point every caller (Quick Registration
     * issuance, the live-pricing preview endpoint) must use to turn a
     * duration-mode selection into a concrete resolved range — so pricing
     * and preview can never structurally disagree about how a mode
     * resolves. Supported modes: 'day' (a single date), 'school_week'
     * (a 7-calendar-day span starting at the given date — non-teaching
     * days inside it are excluded by calculate() exactly like any other
     * range), 'teaching_days' (via resolveForwardFromCount(), the one
     * genuinely new resolution shape), 'month' (one calendar month, or a
     * start/end month pair for a multi-month purchase), and 'custom_range'
     * (an arbitrary, non-month-aligned start/end).
     *
     * @param  array<string, mixed>  $selection  Raw duration-mode fields,
     *         'food_'-prefixed exactly as submitted by the client:
     *         food_duration_mode plus whichever of food_date /
     *         food_week_start / food_start_date+food_day_count /
     *         food_month(+food_end_month) / food_range_start+food_range_end
     *         that mode needs.
     * @return array{coverage_start:string,coverage_end:string,billable_dates:array<int,string>,billable_day_count:int,excluded_day_count:int,rule_version:string,calendar_id:int,duration_mode:?string,requested_day_count:?int}
     */
    public function resolveFromDurationSelection(AcademicYear $year, array $selection): array
    {
        $mode = $selection['food_duration_mode'] ?? null;

        $resolved = match ($mode) {
            'day' => $this->calculate($year, (string) ($selection['food_date'] ?? ''), (string) ($selection['food_date'] ?? '')),
            'school_week' => $this->calculate(
                $year,
                (string) ($selection['food_week_start'] ?? ''),
                Carbon::parse($selection['food_week_start'] ?? now())->addDays(6)->toDateString(),
            ),
            'teaching_days' => $this->resolveForwardFromCount(
                $year,
                (string) ($selection['food_start_date'] ?? ''),
                (int) ($selection['food_day_count'] ?? 0),
            ),
            'month' => $this->calculate(
                $year,
                Carbon::createFromFormat('Y-m', (string) ($selection['food_month'] ?? ''))->startOfMonth()->toDateString(),
                Carbon::createFromFormat('Y-m', (string) ($selection['food_end_month'] ?? $selection['food_month'] ?? ''))->endOfMonth()->toDateString(),
            ),
            'custom_range' => $this->calculate(
                $year,
                (string) ($selection['food_range_start'] ?? ''),
                (string) ($selection['food_range_end'] ?? ''),
            ),
            default => throw ValidationException::withMessages(['food_duration_mode' => 'Укажите режим периода питания.']),
        };

        return $resolved + [
            'duration_mode' => $mode,
            'requested_day_count' => $mode === 'teaching_days' ? (int) ($selection['food_day_count'] ?? 0) : null,
        ];
    }
}
