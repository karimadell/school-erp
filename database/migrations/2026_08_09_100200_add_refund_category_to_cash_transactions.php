<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — refunds post a dedicated cash-out category so they stay
 * distinguishable from ordinary expenses in later cash reporting.
 * enum()->change() widens the MySQL ENUM and rebuilds the SQLite CHECK
 * constraint safely (same pattern as update_invoice_status_enum_values).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->enum('category', ['income', 'expense', 'transfer', 'refund'])
                ->default('income')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->enum('category', ['income', 'expense', 'transfer'])
                ->default('income')
                ->change();
        });
    }
};
