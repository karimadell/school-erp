<?php

namespace Tests\Feature;

use App\Models\Day;
use App\Models\TimetableSetting;
use App\Support\WorkingDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D1 Phase 2: App\Support\WorkingDays is the single place that knows
 * which Day rows are currently non-working — it must read
 * TimetableSetting, never a hard-coded weekday.
 */
class WorkingDaysTest extends TestCase
{
    use RefreshDatabase;

    protected function makeDays(): \Illuminate\Support\Collection
    {
        $codes = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

        return collect($codes)->map(
            fn ($code, $order) => Day::create(['name' => $code, 'code' => $code, 'order' => $order])
        )->values();
    }

    public function test_default_setting_excludes_friday_and_saturday(): void
    {
        $days = $this->makeDays();

        $working = (new WorkingDays())->workingDays($days);

        $this->assertSame(['sun', 'mon', 'tue', 'wed', 'thu'], $working->pluck('code')->values()->all());
    }

    public function test_default_setting_row_is_seeded_by_its_own_migration(): void
    {
        $this->assertSame(['fri', 'sat'], TimetableSetting::current()->non_working_days);
    }

    public function test_a_custom_setting_changes_which_days_are_working_without_any_code_change(): void
    {
        $days = $this->makeDays();

        TimetableSetting::current()->update(['non_working_days' => ['sun']]);

        $working = (new WorkingDays())->workingDays($days);

        $this->assertContains('fri', $working->pluck('code')->all());
        $this->assertContains('sat', $working->pluck('code')->all());
        $this->assertNotContains('sun', $working->pluck('code')->all());
    }
}
