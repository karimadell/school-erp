<?php

namespace Tests\Feature;

use App\Filament\Resources\BellSchedules\BellScheduleResource;
use App\Models\AcademicCalendar;
use App\Models\AcademicYear;
use App\Models\BellSchedule;
use App\Models\BellSchedulePeriod;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\AcademicCalendarService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BellScheduleTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    private AcademicCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01',
            'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        $this->calendar = AcademicCalendar::create([
            'academic_year_id' => $this->year->id, 'weekly_days_off' => ['fri', 'sat'],
        ]);
    }

    public function test_schedule_and_period_can_be_created(): void
    {
        $schedule = $this->schedule(['name' => 'Normal', 'shift' => 2]);
        $period = $schedule->periods()->create([
            'period_number' => 1, 'label' => 'Lesson 1', 'starts_at' => '08:00',
            'ends_at' => '08:45', 'break_after_minutes' => 10, 'is_active' => true,
        ]);

        $this->assertTrue($schedule->academicYear->is($this->year));
        $this->assertSame(2, $schedule->shift);
        $this->assertTrue($period->bellSchedule->is($schedule));
    }

    public function test_invalid_time_range_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->period($this->schedule(), 1, '09:00', '08:45');
    }

    public function test_overlapping_period_is_rejected_but_touching_boundaries_are_allowed(): void
    {
        $schedule = $this->schedule();
        $this->period($schedule, 1, '08:00', '08:45');
        $this->period($schedule, 2, '08:45', '09:30');

        try {
            $this->period($schedule, 3, '09:00', '10:00');
            $this->fail('Expected overlap validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_at', $exception->errors());
        }
    }

    public function test_period_number_is_unique_within_schedule_only(): void
    {
        $first = $this->schedule(['name' => 'Normal']);
        $second = $this->schedule(['name' => 'Winter']);
        $this->period($first, 1, '08:00', '08:45');
        $this->period($second, 1, '09:00', '09:45');

        $this->expectException(ValidationException::class);
        $this->period($first, 1, '10:00', '10:45');
    }

    public function test_only_one_active_default_exists_per_year_and_shift(): void
    {
        $first = $this->schedule(['name' => 'Normal', 'is_default' => true]);
        $second = $this->schedule(['name' => 'Winter', 'is_default' => true]);
        $otherShift = $this->schedule(['name' => 'Shift 2', 'shift' => 2, 'is_default' => true]);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertTrue($otherShift->fresh()->is_default);

        $second->update(['is_active' => false]);
        $this->assertFalse($second->fresh()->is_default);
    }

    public function test_database_guard_prevents_two_concurrent_style_active_defaults_in_the_same_slot(): void
    {
        $first = $this->schedule(['name' => 'Normal', 'is_default' => true]);
        $this->assertSame(1, $first->fresh()->default_slot);

        $this->expectException(QueryException::class);
        DB::table('bell_schedules')->insert([
            'academic_year_id' => $this->year->id,
            'name' => 'Concurrent default',
            'shift' => 1,
            'is_default' => true,
            'is_active' => true,
            'default_slot' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_multiple_non_default_schedules_remain_allowed_by_portable_guard(): void
    {
        $this->schedule(['name' => 'Winter']);
        $this->schedule(['name' => 'Ramadan']);

        $this->assertSame(2, BellSchedule::whereNull('default_slot')->count());
    }

    public function test_historical_schedule_is_locked_and_cannot_be_cascade_deleted(): void
    {
        $schedule = $this->schedule();
        $period = $this->period($schedule, 1, '08:00', '08:45');
        AcademicYear::create([
            'name' => '2027 / 2028', 'start_date' => '2027-09-01',
            'end_date' => '2028-06-30', 'is_active' => true,
        ]);

        try {
            $schedule->update(['name' => 'Changed']);
            $this->fail('Expected historical lock validation.');
        } catch (ValidationException) {
            $this->assertSame('Normal', $schedule->fresh()->name);
        }

        foreach ([
            fn () => $period->update(['ends_at' => '09:00']),
            fn () => $period->delete(),
        ] as $historicalWrite) {
            try {
                $historicalWrite();
                $this->fail('Expected historical period lock validation.');
            } catch (ValidationException) {
                $this->assertDatabaseHas('bell_schedule_periods', ['id' => $period->id, 'ends_at' => '08:45']);
            }
        }

        try {
            $this->year->delete();
            $this->fail('Expected restricted historical FK.');
        } catch (QueryException) {
            $this->assertDatabaseHas('bell_schedules', ['id' => $schedule->id]);
        }
    }

    public function test_calendar_foreign_keys_preserve_valid_references_and_reject_orphans(): void
    {
        $schedule = $this->schedule(['is_default' => true]);
        $this->calendar->update(['default_bell_schedule_id' => $schedule->id]);
        $event = $this->calendar->events()->create([
            'name' => 'Exam bells', 'start_date' => '2026-11-01', 'end_date' => '2026-11-01',
            'type' => CalendarEvent::TYPE_BELL_SCHEDULE_OVERRIDE,
            'bell_schedule_id' => $schedule->id, 'shift' => 1, 'is_active' => true,
        ]);

        $this->assertSame($schedule->id, $this->calendar->fresh()->default_bell_schedule_id);
        $this->assertSame($schedule->id, $event->fresh()->bell_schedule_id);

        try {
            DB::table('academic_calendars')->where('id', $this->calendar->id)
                ->update(['default_bell_schedule_id' => 999999]);
            $this->fail('Expected the real bell schedule foreign key to reject an orphan.');
        } catch (QueryException) {
            $this->assertSame($schedule->id, $this->calendar->fresh()->default_bell_schedule_id);
        }
    }

    public function test_calendar_service_resolves_calendar_default_and_schedule_default(): void
    {
        $normal = $this->schedule(['name' => 'Normal', 'is_default' => true]);
        $secondShift = $this->schedule(['name' => 'Shift 2', 'shift' => 2, 'is_default' => true]);
        $this->calendar->update(['default_bell_schedule_id' => $normal->id]);

        $service = app(AcademicCalendarService::class);
        $this->assertSame($normal->id, $service->bellScheduleFor('2026-09-06', $this->year, 1));
        $this->assertSame($secondShift->id, $service->bellScheduleFor('2026-09-06', $this->year, 2));
    }

    public function test_date_specific_and_shift_specific_overrides_resolve_safely(): void
    {
        $normal = $this->schedule(['name' => 'Normal', 'is_default' => true]);
        $exam = $this->schedule(['name' => 'Exam']);
        $shiftTwo = $this->schedule(['name' => 'Exam shift 2', 'shift' => 2]);
        $this->calendar->update(['default_bell_schedule_id' => $normal->id]);

        foreach ([[$exam, 1], [$shiftTwo, 2]] as [$schedule, $shift]) {
            $this->calendar->events()->create([
                'name' => 'Exam bells', 'start_date' => '2026-11-01', 'end_date' => '2026-11-01',
                'type' => CalendarEvent::TYPE_BELL_SCHEDULE_OVERRIDE,
                'bell_schedule_id' => $schedule->id, 'shift' => $shift, 'is_active' => true,
            ]);
        }

        $service = app(AcademicCalendarService::class);
        $this->assertSame($exam->id, $service->bellScheduleFor('2026-11-01', $this->year, 1));
        $this->assertSame($shiftTwo->id, $service->bellScheduleFor('2026-11-01', $this->year, 2));
    }

    public function test_resource_reuses_admin_only_timetable_permissions(): void
    {
        $user = User::factory()->create();
        Permission::create(['name' => 'view timetable']);
        Permission::create(['name' => 'manage timetable']);
        $this->actingAs($user);
        $this->assertFalse(BellScheduleResource::canViewAny());
        $user->givePermissionTo('view timetable');
        $this->assertTrue(BellScheduleResource::canViewAny());
        $this->assertFalse(BellScheduleResource::canCreate());
        $user->givePermissionTo('manage timetable');
        $this->assertTrue(BellScheduleResource::canCreate());
        $this->assertFalse(BellScheduleResource::canDelete($this->schedule()));
    }

    private function schedule(array $overrides = []): BellSchedule
    {
        return BellSchedule::create(array_merge([
            'academic_year_id' => $this->year->id, 'name' => 'Normal', 'shift' => 1,
            'is_default' => false, 'is_active' => true,
        ], $overrides));
    }

    private function period(BellSchedule $schedule, int $number, string $start, string $end): BellSchedulePeriod
    {
        return $schedule->periods()->create([
            'period_number' => $number, 'starts_at' => $start, 'ends_at' => $end,
            'break_after_minutes' => 0, 'is_active' => true,
        ]);
    }
}
