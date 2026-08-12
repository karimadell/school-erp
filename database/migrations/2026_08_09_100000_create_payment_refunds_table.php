<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — canonical refunds. A payment_refund is an explicit, immutable
 * reversal record that references (never mutates) the original payment, the
 * invoice, the student and the account the money leaves from. Refunds are
 * never modelled as negative InvoicePayment rows (the disabled legacy flow).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_number')->nullable()->unique();

            // Immutable provenance — the money being reversed.
            $table->foreignId('invoice_payment_id')->constrained('invoice_payments')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('invoice_installment_id')->nullable()->constrained('invoice_installments')->cascadeOnUpdate()->nullOnDelete();

            // Where the outgoing money leaves from, and the cash movement it produced.
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('cash_transaction_id')->nullable()->constrained('cash_transactions')->cascadeOnUpdate()->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('EGP');
            $table->string('reason');
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Guards against duplicate submission of the same refund.
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->string('idempotency_hash')->nullable();

            $table->timestamps();

            $table->index('invoice_payment_id');
            $table->index('invoice_id');
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
