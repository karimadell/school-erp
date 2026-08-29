<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Quick Registration document semantics. Records which flow
 * issued an invoice so the "Счета" list can distinguish a Quick
 * Registration front-desk obligation from a deliberately-issued unpaid
 * document (Classic Invoice, Mass Billing, Charge & Collect) without ever
 * hiding the latter. Nullable, no backfill: every existing row and every
 * caller that doesn't pass an origin keeps behaving exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('origin')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
