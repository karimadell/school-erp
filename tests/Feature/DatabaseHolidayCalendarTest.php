<?php

namespace Tests\Feature;

use App\Contracts\HolidayCalendar;
use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D1 Phase 2: holiday infrastructure only. This covers
 * App\Services\DatabaseHolidayCalendar (resolved through the
 * HolidayCalendar contract) in isolation — no TimetableGrid interaction
 * exists yet, per that class's own doc comment.
 */
class DatabaseHolidayCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_date_inside_a_holiday_range_is_reported_as_a_holiday(): void
    {
        Holiday::create([
            'start_date' => '2026-12-25',
            'end_date' => '2027-01-10',
            'type' => Holiday::TYPE_WINTER_VACATION,
            'name' => 'Winter vacation',
        ]);

        $calendar = app(HolidayCalendar::class);

        $this->assertTrue($calendar->isHoliday(Carbon::parse('2026-12-31')));
    }

    public function test_the_range_boundaries_are_inclusive(): void
    {
        Holiday::create([
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-01',
            'type' => Holiday::TYPE_PUBLIC,
            'name' => 'Labour Day',
        ]);

        $calendar = app(HolidayCalendar::class);

        $this->assertTrue($calendar->isHoliday(Carbon::parse('2026-05-01')));
        $this->assertFalse($calendar->isHoliday(Carbon::parse('2026-05-02')));
    }

    public function test_a_date_outside_any_holiday_range_is_not_a_holiday(): void
    {
        Holiday::create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-31',
            'type' => Holiday::TYPE_SUMMER_VACATION,
            'name' => 'Summer vacation',
        ]);

        $calendar = app(HolidayCalendar::class);

        $this->assertFalse($calendar->isHoliday(Carbon::parse('2026-09-15')));
    }

    public function test_a_school_specific_holiday_can_be_scoped_to_an_academic_year(): void
    {
        $year = \App\Models\AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        $holiday = Holiday::create([
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-05',
            'type' => Holiday::TYPE_SCHOOL_SPECIFIC,
            'name' => 'School anniversary',
            'academic_year_id' => $year->id,
        ]);

        $this->assertTrue($holiday->academicYear->is($year));
    }
}
