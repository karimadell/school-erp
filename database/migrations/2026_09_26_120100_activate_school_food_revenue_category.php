<?php

use App\Models\RevenueCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stolovaya Phase 2 (Employee cash purchases) — school_food was seeded
 * INACTIVE (RevenueCategorySeeder) specifically "reserved for the
 * not-yet-built Staff Food feature" (see that seeder's own comment). That
 * feature now exists, so this flips the one, already-existing row.
 *
 * Two-part fix, deliberately narrow:
 *  1. THIS migration activates school_food on any environment where the
 *     row was already seeded before this migration ran (every existing/
 *     UAT database — RevenueCategorySeeder has run there at least once).
 *  2. RevenueCategorySeeder's own default for school_food is separately
 *     changed to `is_active => true` (its "reserved until Staff/Employee
 *     Stolovaya existed" condition is now satisfied) — necessary because
 *     `firstOrCreate()` never updates an existing row, and because on a
 *     genuinely fresh install this migration runs BEFORE any seeder ever
 *     does (standard `migrate` → `db:seed` order), so this migration alone
 *     would find no row to activate and silently no-op there. Confirmed by
 *     inspecting this project's own Feature test fixtures (e.g.
 *     FinanceOperationsTestCase, FinanceFoodBuffetCategorySeparationTest),
 *     which never rely on migrations to seed revenue_categories — they run
 *     RevenueCategorySeeder explicitly per test — so the seeder default is
 *     the only thing a fresh test/dev database ever actually observes.
 *
 * Matched strictly by the stable, immutable `code` column (never the
 * mutable name_ru — see RevenueCategory's own class docblock). Never
 * creates a row (if absent, this is a genuine no-op — creating it here
 * would risk drifting from RevenueCategorySeeder's own name_ru/duplicate
 * intent) and never touches `buffet`/`cafeteria` or any other category.
 */
return new class extends Migration
{
    public function up(): void
    {
        $matches = DB::table('revenue_categories')
            ->where('code', RevenueCategory::CODE_SCHOOL_FOOD)
            ->get();

        if ($matches->isEmpty()) {
            // Fresh install / never-seeded environment — nothing to
            // activate here. RevenueCategorySeeder's own (now-active)
            // default takes care of it whenever seeding actually happens.
            return;
        }

        if ($matches->count() > 1) {
            // `code` carries a UNIQUE constraint (2026_09_10_130000_
            // create_revenue_categories_table), so this should be
            // impossible — fail loudly rather than silently guess which
            // row is "the" school_food category.
            throw new \RuntimeException(
                'Ambiguous school_food RevenueCategory identity: found '.$matches->count().' rows with code=school_food; expected at most 1.'
            );
        }

        DB::table('revenue_categories')
            ->where('code', RevenueCategory::CODE_SCHOOL_FOOD)
            ->update(['is_active' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Deliberately not reversed to false: down() runs on rollback,
        // and re-deactivating a category that may by then have real
        // Employee Stolovaya RevenueEntry rows posted against it would be
        // more destructive than doing nothing. Reserved-inactive was a
        // pre-launch state, not a state this migration should ever
        // restore once the feature has shipped.
    }
};
