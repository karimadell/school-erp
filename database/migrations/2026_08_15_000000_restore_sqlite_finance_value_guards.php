<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $this->replaceTriggers(
            'invoices',
            'status',
            ['unpaid', 'partial', 'paid', 'cancelled'],
        );
        $this->replaceTriggers(
            'cash_transactions',
            'category',
            ['income', 'expense', 'transfer', 'refund'],
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (['invoices_status_check', 'cash_transactions_category_check'] as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}_insert");
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}_update");
        }
    }

    private function replaceTriggers(string $table, string $column, array $values): void
    {
        $name = "{$table}_{$column}_check";
        DB::unprepared("DROP TRIGGER IF EXISTS {$name}_insert");
        DB::unprepared("DROP TRIGGER IF EXISTS {$name}_update");

        $allowed = implode(', ', array_map(fn (string $value) => DB::getPdo()->quote($value), $values));
        DB::unprepared("CREATE TRIGGER {$name}_insert BEFORE INSERT ON {$table} WHEN NEW.{$column} NOT IN ({$allowed}) BEGIN SELECT RAISE(ABORT, 'invalid {$table}.{$column}'); END");
        DB::unprepared("CREATE TRIGGER {$name}_update BEFORE UPDATE OF {$column} ON {$table} WHEN NEW.{$column} NOT IN ({$allowed}) BEGIN SELECT RAISE(ABORT, 'invalid {$table}.{$column}'); END");
    }
};
