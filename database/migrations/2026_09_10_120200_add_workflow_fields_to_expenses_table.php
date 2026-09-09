<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses V1 — additive only. The original expenses table
 * (2026_03_15_023809_create_expenses_table.php) is never edited: it has
 * existed since the initial commit, is exercised by committed regression
 * tests, and may already hold real rows in any environment this branch has
 * run in. Every column here is nullable or defaulted so existing rows stay
 * valid without a data migration, and the legacy free-text `category`
 * column is kept untouched — new rows may use expense_category_id instead.
 *
 * status defaults to 'paid': any pre-existing Expense::create() call that
 * doesn't specify a status (every caller today) keeps behaving exactly like
 * the original unconditional cash-posting hook — instantly paid, posting to
 * the ledger exactly once. New callers can opt into draft/approve/pay by
 * creating with status = 'draft' explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('reference_number')->nullable()->after('id');
            $table->foreignId('expense_category_id')->nullable()->after('category')
                ->constrained('expense_categories')->restrictOnDelete();
            $table->foreignId('payee_id')->nullable()->after('expense_category_id')
                ->constrained('payees')->restrictOnDelete();
            $table->text('notes')->nullable()->after('description');
            $table->string('currency', 3)->default('EGP')->after('amount');
            $table->string('status')->default('paid')->after('cash_account_id');
            $table->string('payment_method')->nullable()->after('status');
            $table->string('external_reference')->nullable()->after('payment_method');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_type')->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
        });

        // Backfill any pre-existing rows before the column becomes unique —
        // same pattern as invoice_payments.payment_number.
        DB::table('expenses')->whereNull('reference_number')->orderBy('id')->each(function ($expense) {
            $year = $expense->created_at ? date('Y', strtotime($expense->created_at)) : date('Y');
            DB::table('expenses')->where('id', $expense->id)->update([
                'reference_number' => sprintf('EXP-%s-%06d', $year, $expense->id),
            ]);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->unique('reference_number');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique(['reference_number']);
            $table->dropConstrainedForeignId('expense_category_id');
            $table->dropConstrainedForeignId('payee_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn([
                'reference_number', 'notes', 'currency', 'status', 'payment_method',
                'external_reference', 'attachment_path', 'attachment_name',
                'attachment_type', 'attachment_size', 'approved_at', 'paid_at',
                'voided_at', 'void_reason',
            ]);
        });
    }
};
