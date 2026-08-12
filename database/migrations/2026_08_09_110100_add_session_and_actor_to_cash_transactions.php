<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — attribute cash movements to a cashier and their open session.
 *
 * Closes the accountability gap: cash transactions carried no user and no shift
 * link. Both columns are nullable — attribution is stamped at creation for new
 * transactions (14a: FK-at-creation, never time-window reconstruction), and
 * historical rows are intentionally left null (no retroactive backfill).
 *
 * cash_session_id uses restrictOnDelete: once a movement is attributed to a
 * session, that session cannot be deleted at the DB level while the movement
 * references it. Combined with the immutable-session policy (sessions are never
 * deleted) there is no normal workflow that can silently null a historical
 * session attribution. created_by keeps nullOnDelete — a user may eventually be
 * removed, and losing the actor id must not block that; the shift link is the
 * accountability anchor and is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('cash_account_id')
                ->constrained('cash_sessions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->after('cash_session_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('cash_session_id');
            $table->dropConstrainedForeignKey('created_by');
        });
    }
};
