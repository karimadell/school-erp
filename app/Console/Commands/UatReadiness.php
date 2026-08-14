<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class UatReadiness extends Command
{
    protected $signature = 'uat:readiness';

    protected $description = 'Verify UAT deployment prerequisites without displaying sensitive configuration';

    public function handle(Migrator $migrator, PermissionRegistrar $permissions): int
    {
        $checks = [
            'database connectivity' => fn () => DB::connection()->getPdo() !== null,
            'migration status' => fn () => $this->pendingMigrations($migrator) === [],
            'required roles' => fn () => collect(['admin', 'accountant', 'cashier'])->every(fn ($role) => Role::query()->where('name', $role)->exists()),
            'required UAT users' => fn () => collect(['admin', 'accountant', 'cashier'])->every(fn ($role) => User::query()->where('email', "{$role}.uat@school.test")->where('is_active', true)->exists()),
            'active academic year' => fn () => AcademicYear::query()->where('is_active', true)->exists(),
            'active cash account' => fn () => CashAccount::query()->where('is_active', true)->where('type', CashAccount::TYPE_CASH)->exists(),
            'permission cache' => function () use ($permissions) {
                $permissions->forgetCachedPermissions();
                return User::query()->where('email', 'accountant.uat@school.test')->first()?->hasRole('accountant') === true;
            },
            'finance query' => fn () => Invoice::query()->selectRaw('COUNT(*) AS invoice_count, COALESCE(SUM(total_amount), 0) AS total')->first() !== null,
        ];

        $failed = [];
        foreach ($checks as $name => $check) {
            try {
                $passed = $check() === true;
            } catch (Throwable) {
                $passed = false;
            }
            $this->line(sprintf('[%s] %s', $passed ? 'OK' : 'FAIL', $name));
            if (! $passed) {
                $failed[] = $name;
            }
        }

        if ($failed !== []) {
            $this->error('UAT readiness checks failed. Review the named checks; no sensitive details were displayed.');
            return self::FAILURE;
        }

        $this->info('UAT readiness checks passed.');
        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function pendingMigrations(Migrator $migrator): array
    {
        $files = $migrator->getMigrationFiles(database_path('migrations'));
        $ran = $migrator->getRepository()->getRan();

        return array_values(array_diff(array_keys($files), $ran));
    }
}
