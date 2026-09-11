<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-Tuition Revenues V1 — records income that isn't student tuition/service
 * billing (cafeteria, donations, fines, other) without a fake Student,
 * Invoice, or InvoicePayment. Status: draft -> posted -> reversed.
 *
 * revenue_category_id is NOT nullable — every RevenueEntry belongs to a
 * category, unlike Expense's optional structured category (which coexists
 * with a legacy free-text column that doesn't exist here — a clean slate).
 * cash_account_id/payment_method stay nullable: a draft may be incomplete on
 * those two fields; everything else (amount, category, date) is required
 * even for a draft (RevenueService enforces this at the application layer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_entries', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->nullable()->unique();
            $table->foreignId('revenue_category_id')->constrained('revenue_categories')->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->restrictOnDelete();
            $table->string('payer_name')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('revenue_date');
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->string('payment_method')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_type')->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_entries');
    }
};
