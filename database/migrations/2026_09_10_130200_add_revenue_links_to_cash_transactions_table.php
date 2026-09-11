<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-Tuition Revenues V1 — two independent nullable+unique FK columns,
 * NOT one shared column, because a single RevenueEntry needs to be
 * referenced by up to two different cash_transactions rows over its life:
 * the original posting (type=in) and, if later reversed, a second,
 * separate reversing row (type=out). A single revenue_entry_id column with
 * a unique constraint could hold only one of the two.
 *
 * - revenue_entry_id: the transaction that originally posted this
 *   RevenueEntry. Unique -> the DB enforces "at most one original posting
 *   per RevenueEntry" (RevenueService's existence-check-then-insert is the
 *   idempotent app-level guard; this constraint is the deterministic
 *   backstop against a concurrent duplicate).
 * - reversed_revenue_entry_id: the transaction that reverses this
 *   RevenueEntry, if any. Unique -> the DB enforces "at most one reversal
 *   per RevenueEntry" the same way. A row is either an original posting
 *   (revenue_entry_id set, reversed_revenue_entry_id null) or a reversal
 *   (the other way around) — never both on the same row.
 *
 * down() drops the unique indexes before the columns in two separate
 * Schema::table() calls — combining them in one pass fails on SQLite
 * ("error in index ... after drop column"), the same limitation found and
 * fixed for Expenses V1's structurally identical expense_id column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->foreignId('revenue_entry_id')->nullable()->unique()
                ->constrained('revenue_entries')->restrictOnDelete();
            $table->foreignId('reversed_revenue_entry_id')->nullable()->unique()
                ->constrained('revenue_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropUnique(['revenue_entry_id']);
            $table->dropUnique(['reversed_revenue_entry_id']);
        });
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revenue_entry_id');
            $table->dropConstrainedForeignId('reversed_revenue_entry_id');
        });
    }
};
