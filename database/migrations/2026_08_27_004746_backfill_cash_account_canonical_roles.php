<?php

use App\Services\Finance\CashAccountRoleService;
use Illuminate\Database\Migrations\Migration;

/**
 * Cash Operations — safe role backfill/adoption.
 *
 * Repairs the class of bug that caused the UAT duplicate
 * ("Операционная касса" created alongside the pre-existing, differently
 * named "UAT — Основная касса") on any database this runs against,
 * fresh or already carrying history, without ever guessing. The actual
 * adopt/create/flag-ambiguous algorithm lives in CashAccountRoleService
 * (shared, tested in isolation) — this migration just invokes it once
 * per role at deploy time.
 *
 * The already-applied 2026_08_26_233916 migration is intentionally left
 * unedited (per project policy, an applied migration is never rewritten
 * as the fix) — this migration is the forward repair for any database it
 * already ran against, and is equally correct on a database where it
 * never ran at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(CashAccountRoleService::class)->ensureAllRoles();
    }

    public function down(): void
    {
        // Deliberately no-op. Clearing role or deleting rows here could
        // discard a real financial account or break the canonical lookup
        // for anything created after this ran — CLAUDE.md forbids deleting
        // financial data. Reverse by hand, deliberately, if ever needed.
    }
};
