<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UatSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class UatReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_uat_seeder_refuses_non_uat_environments_before_writing(): void
    {
        $this->expectException(RuntimeException::class);

        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\UatSeeder', '--force' => true]);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_readiness_command_passes_for_a_complete_safe_scenario(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        foreach (['admin', 'accountant', 'cashier'] as $role) {
            $user = User::create(['name' => $role, 'email' => "{$role}.uat@school.test", 'password' => Hash::make('test-only-password'), 'is_active' => true]);
            $user->assignRole($role);
        }
        AcademicYear::create(['name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true]);
        CashAccount::create(['name' => 'UAT cash', 'type' => CashAccount::TYPE_CASH, 'balance' => 0, 'is_active' => true]);

        $this->artisan('uat:readiness')->assertSuccessful();
    }

    public function test_uat_seeder_is_secret_driven_and_idempotent(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        config()->set('app.env', 'staging');
        config()->set('uat.passwords', [
            'admin' => 'admin-test-secret-123',
            'accountant' => 'accountant-test-secret-123',
            'cashier' => 'cashier-test-secret-123',
            'reception' => 'reception-test-secret-123',
        ]);

        try {
            $this->seed(UatSeeder::class);
            $this->seed(UatSeeder::class);

            $this->assertDatabaseCount('students', 12);
            $this->assertDatabaseCount('invoices', 3);
            $this->assertDatabaseCount('invoice_payments', 2);
            $this->assertDatabaseHas('users', ['email' => 'cashier.uat@school.test', 'is_active' => true]);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
            config()->set('app.env', 'testing');
        }
    }
}
