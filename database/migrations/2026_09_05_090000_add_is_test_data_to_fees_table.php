<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-Premium-UI corrective pass — Quick Registration operator UX.
 *
 * Mirrors payment_plans.is_test_data (2026_09_01_090000) exactly: purely
 * additive, every existing Fee row defaults to is_test_data=false (no
 * existing Fee's meaning changes). Lets Quick Registration's operational
 * fee-discovery query exclude internal/test fixture Fee records (created
 * ad-hoc directly against a live environment, with no code-provable
 * identity — see the audit that preceded this migration) from the
 * employee-facing service list, WITHOUT deleting or deactivating them and
 * WITHOUT any name-pattern matching. This column marks nothing by itself —
 * no row is flagged here; a separate, explicit, ID-driven follow-up
 * command marks specific already-identified Fee ids once their exact live
 * ids are confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            $table->boolean('is_test_data')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            $table->dropColumn('is_test_data');
        });
    }
};
