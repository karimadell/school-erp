<?php

namespace Tests\Feature;

use App\Filament\Resources\AcademicYears\Pages\EditAcademicYear;
use App\Models\AcademicYear;
use App\Models\AcademicYearUnlock;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Item 2: temporary, whole-academic-year unlock. Approved policy: dedicated
 * permission ('unlock historical academic year', admin-only for the
 * initial implementation — kept separate from 'manage academic years'),
 * required reason, required future expiry, acting user recorded, no
 * permanent option, audit logged (via the existing AuditObserver).
 */
class AcademicYearUnlockTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithRole(string $role): User
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function makeHistoricalYear(): AcademicYear
    {
        return AcademicYear::create([
            'name' => '2024 / 2025', 'start_date' => '2024-09-01', 'end_date' => '2025-05-31', 'is_active' => false,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Model-level guarantees
    |--------------------------------------------------------------------------
    */

    public function test_a_year_with_a_future_unlock_is_reported_as_unlocked(): void
    {
        $year = $this->makeHistoricalYear();

        AcademicYearUnlock::create([
            'academic_year_id' => $year->id, 'reason' => 'Backfill correction', 'unlocked_by' => null,
            'expires_at' => now()->addHour(),
        ]);

        $this->assertTrue($year->isUnlocked());
    }

    public function test_a_year_with_only_an_expired_unlock_is_still_reported_as_locked(): void
    {
        $year = $this->makeHistoricalYear();

        AcademicYearUnlock::create([
            'academic_year_id' => $year->id, 'reason' => 'Old unlock', 'unlocked_by' => null,
            'expires_at' => now()->subHour(),
        ]);

        $this->assertFalse($year->isUnlocked());
    }

    public function test_an_expired_unlock_record_is_not_deleted(): void
    {
        // Expired unlocks stay for audit history — only excluded from the
        // isUnlocked() check, never physically removed.
        $year = $this->makeHistoricalYear();

        $unlock = AcademicYearUnlock::create([
            'academic_year_id' => $year->id, 'reason' => 'Old unlock', 'unlocked_by' => null,
            'expires_at' => now()->subHour(),
        ]);

        $this->assertDatabaseHas('academic_year_unlocks', ['id' => $unlock->id]);
    }

    public function test_a_year_with_no_unlock_at_all_is_reported_as_locked(): void
    {
        $year = $this->makeHistoricalYear();

        $this->assertFalse($year->isUnlocked());
    }

    /*
    |--------------------------------------------------------------------------
    | Filament unlock action — permission, required fields, actor, audit
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_see_and_use_the_unlock_action(): void
    {
        $admin = $this->userWithRole('admin');
        $year = $this->makeHistoricalYear();

        Livewire::actingAs($admin)
            ->test(EditAcademicYear::class, ['record' => $year->id])
            ->callAction('unlock', [
                'reason' => 'Correcting a late grade entry',
                'expires_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ]);

        $this->assertDatabaseHas('academic_year_unlocks', [
            'academic_year_id' => $year->id,
            'reason' => 'Correcting a late grade entry',
            'unlocked_by' => $admin->id,
        ]);
        $this->assertTrue($year->fresh()->isUnlocked());
    }

    public function test_school_admin_cannot_see_the_unlock_action(): void
    {
        // Approved decision: admin-only for the initial implementation —
        // not extended to school-admin even though school-admin otherwise
        // mirrors admin's full operational permission set.
        $schoolAdmin = $this->userWithRole('school-admin');
        $year = $this->makeHistoricalYear();

        Livewire::actingAs($schoolAdmin)
            ->test(EditAcademicYear::class, ['record' => $year->id])
            ->assertActionHidden('unlock');
    }

    public function test_principal_cannot_see_the_unlock_action(): void
    {
        // Approved decision: not principal either, despite principal
        // already holding 'manage academic years'.
        $principal = $this->userWithRole('principal');
        $year = $this->makeHistoricalYear();

        Livewire::actingAs($principal)
            ->test(EditAcademicYear::class, ['record' => $year->id])
            ->assertActionHidden('unlock');
    }

    public function test_unlock_action_is_hidden_for_the_active_year(): void
    {
        // The active year is never locked in the first place.
        $admin = $this->userWithRole('admin');
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(EditAcademicYear::class, ['record' => $year->id])
            ->assertActionHidden('unlock');
    }

    public function test_unlocking_creates_an_audit_log_entry(): void
    {
        $admin = $this->userWithRole('admin');
        $year = $this->makeHistoricalYear();

        Livewire::actingAs($admin)
            ->test(EditAcademicYear::class, ['record' => $year->id])
            ->callAction('unlock', [
                'reason' => 'Backfill',
                'expires_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'model' => 'AcademicYearUnlock',
        ]);
    }
}
