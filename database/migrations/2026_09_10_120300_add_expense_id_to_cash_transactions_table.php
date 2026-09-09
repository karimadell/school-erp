<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses V1 — the ledger references its source, the same direction as the
 * pre-existing invoice_payment_id/teacher_salary_id columns. Nullable +
 * unique: at most one cash_transactions row may ever be tagged to a given
 * expense (the DB-level backstop for idempotent posting — ExpenseService
 * checks for an existing row before inserting, this constraint is what
 * makes a concurrent duplicate insert fail instead of double-posting), while
 * any number of unrelated transactions keep a null expense_id.
 *
 * No mirrored expenses.cash_transaction_id column: Expense's own
 * cashTransaction() inverse relation (hasOne via this FK) covers every
 * lookup need without a second column to keep in sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->foreignId('expense_id')->nullable()->unique()->after('teacher_salary_id')
                ->constrained('expenses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_id');
        });
    }
};
