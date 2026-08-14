<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->changeStatusValues(['unpaid', 'partial', 'paid', 'cancelled']);
    }

    public function down(): void
    {
        $this->changeStatusValues(['unpaid', 'paid', 'cancelled']);
    }

    private function changeStatusValues(array $values): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check');

            Schema::table('invoices', function (Blueprint $table) {
                $table->string('status')->nullable(false)->default('unpaid')->change();
            });

            $allowed = implode(', ', array_map(fn (string $value) => DB::getPdo()->quote($value), $values));
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ({$allowed}))");

            return;
        }

        Schema::table('invoices', function (Blueprint $table) use ($values) {
            $table->enum('status', $values)
                ->nullable(false)
                ->default('unpaid')
                ->change();
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->replaceSqliteStatusTriggers($values);
        }
    }

    private function replaceSqliteStatusTriggers(array $values): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS invoices_status_check_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS invoices_status_check_update');

        $allowed = implode(', ', array_map(fn (string $value) => DB::getPdo()->quote($value), $values));
        DB::unprepared("CREATE TRIGGER invoices_status_check_insert BEFORE INSERT ON invoices WHEN NEW.status NOT IN ({$allowed}) BEGIN SELECT RAISE(ABORT, 'invalid invoices.status'); END");
        DB::unprepared("CREATE TRIGGER invoices_status_check_update BEFORE UPDATE OF status ON invoices WHEN NEW.status NOT IN ({$allowed}) BEGIN SELECT RAISE(ABORT, 'invalid invoices.status'); END");
    }
};
