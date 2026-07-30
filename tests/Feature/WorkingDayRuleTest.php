<?php

namespace Tests\Feature;

use App\Models\Day;
use App\Models\TimetableSetting;
use App\Support\TimetableSlot;
use App\Support\WorkingDayRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batch 3 / Working Days Enforcement (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
 * WorkingDayRule tested in isolation, independent of
 * CurriculumAwareTimetableConflictChecker's orchestration and
 * independent of TimetableGrid's wiring (covered in
 * TimetableGridManualEditTest).
 */
class WorkingDayRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function makeDay(string $code, int $order = 0): Day
    {
        return Day::create(['name' => $code, 'code' => $code, 'order' => $order]);
    }

    protected function makeSlot(int $dayId): TimetableSlot
    {
        return new TimetableSlot(classId: 1, dayId: $dayId, periodId: 1, teacherId: 1, subjectId: 1);
    }

    public function test_rule_rejects_friday_under_the_default_configuration(): void
    {
        $friday = $this->makeDay('fri');

        $this->assertSame(
            'timetable.non_working_day',
            (new WorkingDayRule())->check($this->makeSlot($friday->id))
        );
    }

    public function test_rule_rejects_saturday_under_the_default_configuration(): void
    {
        $saturday = $this->makeDay('sat');

        $this->assertSame(
            'timetable.non_working_day',
            (new WorkingDayRule())->check($this->makeSlot($saturday->id))
        );
    }

    public function test_rule_accepts_a_configured_working_day(): void
    {
        $monday = $this->makeDay('mon');

        $this->assertNull((new WorkingDayRule())->check($this->makeSlot($monday->id)));
    }

    public function test_rule_respects_a_custom_timetable_setting_without_any_code_change(): void
    {
        $sunday = $this->makeDay('sun');
        $friday = $this->makeDay('fri');

        TimetableSetting::current()->update(['non_working_days' => ['sun']]);

        $this->assertSame(
            'timetable.non_working_day',
            (new WorkingDayRule())->check($this->makeSlot($sunday->id))
        );
        $this->assertNull((new WorkingDayRule())->check($this->makeSlot($friday->id)));
    }
}
