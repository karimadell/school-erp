<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance V2, Phase 1A — additive Payment Allocation foundation
 * (docs/finance-v2-architecture.md §7).
 *
 * Records which InvoiceItem(s) a confirmed InvoicePayment actually paid
 * down. Write-once, immutable, append-only: a payment_allocations row is
 * never updated once created (see PaymentAllocation::booted()), matching
 * invoice_payments' own immutability. No column here is nullable — every
 * row must fully identify its payment, its item, and a positive amount;
 * there is no "unallocated" row, only the absence of one.
 *
 * Deliberately no updated_at (created_at only): this table has no update
 * path to timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_payment_id')
                ->constrained('invoice_payments')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('invoice_item_id')
                ->constrained('invoice_items')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            $table->timestamp('created_at')->useCurrent();

            $table->index('invoice_payment_id');
            $table->index('invoice_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
