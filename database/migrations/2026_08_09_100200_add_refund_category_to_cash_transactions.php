<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — refunds post a dedicated cash-out category so they stay
 * distinguishable from ordinary expenses in later cash reporting.
 * Keep the dedicated refund category portable across supported databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->changeCategoryValues(['income', 'expense', 'transfer', 'refund']);
    }

    public function down(): void
    {
        $this->changeCategoryValues(['income', 'expense', 'transfer']);
    }

    private function changeCategoryValues(array $values): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cash_transactions DROP CONSTRAINT IF EXISTS cash_transactions_category_check');

            Schema::table('cash_transactions', function (Blueprint $table) {
                $table->string('category')->nullable(false)->default('income')->change();
            });

            $allowed = implode(', ', array_map(fn (string $value) => DB::getPdo()->quote($value), $values));
            DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_category_check CHECK (category IN ({$allowed}))");

            return;
        }

        Schema::table('cash_transactions', function (Blueprint $table) use ($values) {
            $table->enum('category', $values)
                ->nullable(false)
                ->default('income')
                ->change();
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->replaceSqliteCategoryTriggers($values);
        }
    }

    private function replaceSqliteCategoryTriggers(array $values): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS cash_transactions_category_check_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS cash_transactions_category_check_update');

        $allowed = implode(', ', array_map(fn (string $value) => DB::getPdo()->quote($value), $values));
        DB::unprepared("CREATE TRIGGER cash_transactions_category_check_insert BEFORE INSERT ON cash_transactions WHEN NEW.category NOT IN ({$allowed}) BEGIN SELECT RAISE(ABORT, 'invalid cash_transactions.category'); END");
        DB::unprepared("CREATE TRIGGER cash_transactions_category_check_update BEFORE UPDATE OF category ON cash_transactions WHEN NEW.category NOT IN ({$allowed}) BEGIN SELECT RAISE(ABORT, 'invalid cash_transactions.category'); END");
    }
};
