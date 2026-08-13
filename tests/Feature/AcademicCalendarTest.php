<?php

namespace Tests\Feature;

use App\Filament\Resources\AcademicCalendars\AcademicCalendarResource;
use App\Models\AcademicCalendar;
use App\Models\AcademicYear;
use App\Models\BellSchedule;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\AcademicCalendarService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AcademicCalendarTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    private AcademicCalendar $calendar;

    private AcademicCalendarService $service;

    private BellSchedule $defaultBellSchedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->year = AcademicYear::create([
            'name' => '2026 / 2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_active' => true,
        ]);
        $this->defaultBellSchedule = BellSchedule::create([
            'academic_year_id' => $this->year->id,
            'name' => 'Normal',
            'shift' => 1,
            'is_default' => true,
            'is_active' => true,
        ]);
        $this->calendar = AcademicCalendar::create([
            'academic_year_id' => $this->year->id,
            'weekly_days_off' => ['fri', 'sat'],
            'default_bell_schedule_id' => $this->defaultBellSchedule->id,
        ]);
        $this->service = app(AcademicCalendarService::class);
    }

    public function test_calendar_is_year_scoped_and_weekly_days_off_are_configurable(): void
    {
        $this->assertTrue($this->calendar->academicYear->is($this->year));
        $this->assertSame(['fri', 'sat'], $this->calendar->weekly_days_off);
        $this->assertFalse($this->service->isTeachingDay('2026-09-04'));
        $this->assertTrue($this->service->isTeachingDay('2026-09-06'));

        $this->calendar->update(['weekly_days_off' => ['sun']]);

        $this->assertFalse($this->service->isTeachingDay('2026-09-06'));
        $this->assertTrue($this->service->isTeachingDay('2026-09-04'));
    }

    public function test_a_new_calendar_requires_an_active_academic_year_but_existing_history_is_preserved(): void
    {
        $inactiveYear = AcademicYear::create([
            'name' => '2027 / 2028',
            'start_date' => '2027-09-01',
            'end_date' => '2028-06-30',
            'is_active' => false,
        ]);

        try {
            AcademicCalendar::create([
                'academic_year_id' => $inactiveYear->id,
                'weekly_days_off' => ['fri'],
            ]);
            $this->fail('Expected inactive academic year validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('academic_calendar.validation.active_academic_year_required'),
                $exception->errors()['academic_year_id'][0],
            );
        }

        $nextYear = AcademicYear::create([
            'name' => '2028 / 2029',
            'start_date' => '2028-09-01',
            'end_date' => '2029-06-30',
            'is_active' => true,
        ]);

        $this->assertFalse($this->year->fresh()->is_active);
        $this->assertDatabaseHas('academic_calendars', ['id' => $this->calendar->id]);

        $this->assertTrue($nextYear->is_active);
        $this->assertTrue($this->calendar->fresh()->academicYear->is($this->year));
    }

    public function test_official_and_school_holiday_ranges_are_non_teaching(): void
    {
        $this->event([
            'type' => CalendarEvent::TYPE_OFFICIAL_HOLIDAY,
            'start_date' => '2026-10-06',
            'end_date' => '2026-10-06',
        ]);
        $this->event([
            'type' => CalendarEvent::TYPE_SCHOOL_HOLIDAY,
            'start_date' => '2026-12-20',
            'end_date' => '2026-12-31',
        ]);

        $this->assertFalse($this->service->isTeachingDay('2026-10-06'));
        $this->assertFalse($this->service->isTeachingDay('2026-12-23'));
        $this->assertTrue($this->service->isTeachingDay('2027-01-03'));
    }

    public function test_teaching_override_has_precedence_over_holiday_and_weekly_day_off(): void
    {
        $this->event([
            'type' => CalendarEvent::TYPE_OFFICIAL_HOLIDAY,
            'start_date' => '2026-10-09',
            'end_date' => '2026-10-09',
        ]);
        $this->event([
            'type' => CalendarEvent::TYPE_TEACHING_OVERRIDE,
            'effect' => CalendarEvent::EFFECT_TEACHING_DAY,
            'start_date' => '2026-10-09',
            'end_date' => '2026-10-09',
        ]);

        $this->assertTrue($this->service->isTeachingDay('2026-10-09'));
    }

    public function test_teaching_override_can_make_a_normal_day_non_teaching_or_shortened(): void
    {
        $this->event([
            'type' => CalendarEvent::TYPE_TEACHING_OVERRIDE,
            'effect' => CalendarEvent::EFFECT_NON_TEACHING,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-07',
        ]);
        $this->event([
            'type' => CalendarEvent::TYPE_TEACHING_OVERRIDE,
            'effect' => CalendarEvent::EFFECT_SHORTENED,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-08',
        ]);

        $this->assertFalse($this->service->isTeachingDay('2026-09-07'));
        $this->assertTrue($this->service->isTeachingDay('2026-09-08'));
    }

    public function test_bell_schedule_override_wins_and_inactive_events_are_ignored(): void
    {
        $override = BellSchedule::create([
            'academic_year_id' => $this->year->id, 'name' => 'Exam', 'shift' => 1,
            'is_default' => false, 'is_active' => true,
        ]);
        $inactiveOverride = BellSchedule::create([
            'academic_year_id' => $this->year->id, 'name' => 'Winter', 'shift' => 1,
            'is_default' => false, 'is_active' => true,
        ]);
        $this->event([
            'type' => CalendarEvent::TYPE_BELL_SCHEDULE_OVERRIDE,
            'bell_schedule_id' => $override->id,
            'shift' => 1,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-01',
        ]);
        $this->event([
            'type' => CalendarEvent::TYPE_BELL_SCHEDULE_OVERRIDE,
            'bell_schedule_id' => $inactiveOverride->id,
            'shift' => 1,
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'is_active' => false,
        ]);

        $this->assertSame($override->id, $this->service->bellScheduleFor('2026-11-01'));
        $this->assertSame($this->defaultBellSchedule->id, $this->service->bellScheduleFor('2026-11-02'));
    }

    public function test_explicit_academic_year_never_resolves_a_date_outside_its_boundaries(): void
    {
        $this->assertNull($this->service->calendarFor('2026-08-31', $this->year));
        $this->assertNull($this->service->calendarFor('2027-07-01', $this->year->id));
        $this->assertFalse($this->service->isTeachingDay('2026-08-31', $this->year));
        $this->assertNull($this->service->bellScheduleFor('2027-07-01', $this->year->id));
    }

    public function test_deleting_an_academic_year_cannot_cascade_away_calendar_history(): void
    {
        try {
            $this->year->delete();
            $this->fail('Expected the historical calendar foreign key to restrict deletion.');
        } catch (QueryException) {
            $this->assertDatabaseHas('academic_years', ['id' => $this->year->id]);
            $this->assertDatabaseHas('academic_calendars', ['id' => $this->calendar->id]);
        }
    }

    public function test_event_validation_rejects_bad_ranges_out_of_year_dates_and_missing_override_data(): void
    {
        foreach ([
            ['start_date' => '2026-09-10', 'end_date' => '2026-09-09'],
            ['start_date' => '2027-07-01', 'end_date' => '2027-07-01'],
        ] as $dates) {
            try {
                $this->event(array_merge(['type' => CalendarEvent::TYPE_SCHOOL_HOLIDAY], $dates));
                $this->fail('Expected date validation to fail.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->expectException(ValidationException::class);
        $this->event([
            'type' => CalendarEvent::TYPE_BELL_SCHEDULE_OVERRIDE,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-10',
        ]);
    }

    public function test_resource_uses_timetable_permissions_and_never_hard_deletes_history(): void
    {
        $user = User::factory()->create();
        Permission::create(['name' => 'view timetable']);
        Permission::create(['name' => 'manage timetable']);

        $this->actingAs($user);
        $this->assertFalse(AcademicCalendarResource::canViewAny());

        $user->givePermissionTo('view timetable');
        $this->assertTrue(AcademicCalendarResource::canViewAny());
        $this->assertFalse(AcademicCalendarResource::canCreate());

        $user->givePermissionTo('manage timetable');
        $this->assertTrue(AcademicCalendarResource::canCreate());
        $this->assertFalse(AcademicCalendarResource::canDelete($this->calendar));
    }

    private function event(array $attributes): CalendarEvent
    {
        return $this->calendar->events()->create(array_merge([
            'name' => 'Calendar event',
            'effect' => null,
            'notes' => null,
            'is_active' => true,
        ], $attributes));
    }
}
