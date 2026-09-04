<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One-off smoke test for the finance:mark-test-fees command added by the
 * Decision 2 corrective pass — not part of the requested test list, just
 * confirming the command itself actually works before shipping it.
 */
class MarkTestFeesCommandSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write_then_apply_does(): void
    {
        $fee = Fee::create(['name_ru' => 'UAT_FINANCE_PHASE2_TEST', 'category' => 'transport', 'amount' => '0.00', 'is_active' => true]);

        $this->artisan('finance:mark-test-fees', ['ids' => [$fee->id, 9999]])->assertSuccessful();
        $this->assertFalse($fee->fresh()->is_test_data);

        $this->artisan('finance:mark-test-fees', ['ids' => [$fee->id, 9999], '--apply' => true])->assertSuccessful();
        $this->assertTrue($fee->fresh()->is_test_data);

        // Idempotent re-run.
        $this->artisan('finance:mark-test-fees', ['ids' => [$fee->id], '--apply' => true])->assertSuccessful();
        $this->assertTrue($fee->fresh()->is_test_data);
    }
}
