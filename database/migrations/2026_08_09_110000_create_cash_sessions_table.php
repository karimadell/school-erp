<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Cash-drawer sessions (shifts).
 *
 * A session brackets a cashier's shift on one cash-type drawer: it opens with a
 * system-derived expected baseline, gathers all cash movements posted while it
 * is open (linked by FK, see the companion migration), and closes with a single
 * counted total that the system reconciles against the expected cash to surface
 * a shortage/overage. Closed sessions are immutable.
 *
 * cash_account_id uses restrictOnDelete (not cascade): a drawer with historical
 * sessions cannot be hard-deleted at the DB level, so immutable financial
 * history can never be silently wiped out by removing its account. This is a
 * database-level guarantee, independent of any Eloquent model event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->foreignId('reconciled_by')->nullable()->constrained('users');

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();

            // Expected baseline at open. Derived, never a free-form manual amount
            // unless an authorized override is recorded (source = 'override').
            $table->decimal('opening_expected', 12, 2);
            $table->string('opening_expected_source'); // previous_session | account_balance | override

            // Populated at close.
            $table->decimal('closing_counted', 12, 2)->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable(); // counted - expected; <0 shortage, >0 overage

            $table->string('status')->default('open'); // open | closed | reconciled
            $table->string('open_note')->nullable();
            $table->string('close_note')->nullable(); // required when |variance| > 0

            $table->timestamps();

            $table->index(['cash_account_id', 'status']);
            $table->index(['opened_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
