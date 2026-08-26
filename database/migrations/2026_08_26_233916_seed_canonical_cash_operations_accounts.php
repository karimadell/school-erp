<?php

use App\Models\CashAccount;
use Illuminate\Database\Migrations\Migration;

/**
 * Cash Operations Phase 1 — foundational accounts.
 *
 * cash_accounts.type is a free-form string with no schema-level enum (see
 * 2026_02_21_215200_create_cash_accounts_table /
 * 2026_03_04_204123_add_default_type_to_cash_accounts_table), so the two
 * new account types this phase needs (owner cash, InstaPay) need no
 * column change at all — only these two new CashAccount::TYPE_* values.
 *
 * firstOrCreate by name: safe to run once per environment, a no-op on any
 * later run, and never touches accounts that already exist (in
 * particular, UatSeeder's "UAT — Основная касса" fixture is untouched —
 * this migration only ever adds the four rows below if missing).
 */
return new class extends Migration
{
    public function up(): void
    {
        $accounts = [
            ['name' => 'Операционная касса', 'type' => CashAccount::TYPE_CASH],
            ['name' => 'Касса владельца', 'type' => CashAccount::TYPE_OWNER_CASH],
            ['name' => 'Банковский счёт', 'type' => CashAccount::TYPE_BANK],
            ['name' => 'InstaPay', 'type' => CashAccount::TYPE_INSTAPAY],
        ];

        foreach ($accounts as $account) {
            CashAccount::firstOrCreate(
                ['name' => $account['name']],
                ['type' => $account['type'], 'balance' => '0.00', 'is_active' => true],
            );
        }
    }

    public function down(): void
    {
        // Deliberately no-op: these accounts may already carry real
        // transactions/transfers by the time anyone rolls back, and
        // CLAUDE.md forbids deleting financial data. Remove manually via
        // the UI if a specific account is genuinely unused.
    }
};
