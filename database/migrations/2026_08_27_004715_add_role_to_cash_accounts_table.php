<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash Operations — canonical account roles.
 *
 * Root cause of the UAT duplicate account: 2026_08_26_233916 identified
 * "the operating account" by exact display name, so a differently-named
 * pre-existing drawer (UatSeeder's "UAT — Основная касса") wasn't
 * recognised and a second row got created. Display names must never be
 * financial identity — this migration adds the stable semantic anchor
 * instead. `type` keeps meaning "what kind of account is this physically"
 * (cash/bank/owner_cash/instapay, already free-form, unchanged); `role`
 * answers "what does this specific account do in the canonical Cash
 * Operations workflow" (operating/owner/bank/instapay), and is null for
 * every other, perfectly legitimate cash-type drawer (Phase 3 already
 * supports and needs multiple of those).
 *
 * A plain nullable-and-unique column is enough to guarantee "at most one
 * canonical account per role": every supported database (MySQL,
 * PostgreSQL, SQLite — the three this project actually runs on) treats
 * NULLs as pairwise distinct under a UNIQUE index, per the SQL standard,
 * so any number of ordinary (role=null) drawers can coexist while at
 * most one row may ever hold role='operating', etc. No portability risk,
 * unlike a Postgres-only partial index.
 *
 * The value guard mirrors the project's existing pattern for exactly
 * this situation (cash_transactions.category, cash_transfers.transfer_type)
 * — a real CHECK constraint on Postgres, an equivalent trigger pair on
 * SQLite — except it explicitly also allows NULL, which those two
 * columns never needed to.
 */
return new class extends Migration
{
    private const ROLES = ['operating', 'owner', 'bank', 'instapay'];

    public function up(): void
    {
        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->string('role')->nullable()->unique()->after('type');
        });

        $this->applyRoleGuard();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cash_accounts DROP CONSTRAINT IF EXISTS cash_accounts_role_check');
        }
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS cash_accounts_role_check_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS cash_accounts_role_check_update');
        }

        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->dropUnique(['role']);
            $table->dropColumn('role');
        });
    }

    private function applyRoleGuard(): void
    {
        $driver = DB::connection()->getDriverName();
        $allowed = implode(', ', array_map(fn (string $value) => DB::getPdo()->quote($value), self::ROLES));

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE cash_accounts ADD CONSTRAINT cash_accounts_role_check CHECK (role IS NULL OR role IN ({$allowed}))");

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER cash_accounts_role_check_insert BEFORE INSERT ON cash_accounts WHEN NEW.role IS NOT NULL AND NEW.role NOT IN ({$allowed}) BEGIN SELECT RAISE(ABORT, 'invalid cash_accounts.role'); END");
            DB::unprepared("CREATE TRIGGER cash_accounts_role_check_update BEFORE UPDATE OF role ON cash_accounts WHEN NEW.role IS NOT NULL AND NEW.role NOT IN ({$allowed}) BEGIN SELECT RAISE(ABORT, 'invalid cash_accounts.role'); END");
        }

        // MySQL: no native portable CHECK-on-string enforcement path is
        // used elsewhere in this project either (cash_transactions.category
        // relies on the same three-way branch) — application-level
        // validation (CashAccount role constants + the resolver/backfill
        // migration below) is the guard on that driver, matching existing
        // precedent.
    }
};
