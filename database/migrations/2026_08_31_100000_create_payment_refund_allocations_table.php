<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance V2, Phase 1D (docs/finance-v2-architecture.md §19 Phase 1D).
 *
 * Which PaymentAllocation(s) a confirmed PaymentRefund actually reversed.
 * Mirrors payment_allocations exactly: write-once, immutable, append-only
 * (see PaymentRefundAllocation::booted()). No column here is nullable —
 * every row must fully identify its refund, the original payment
 * allocation it reverses, and a positive amount; there is no "unattributed"
 * row, only the absence of one (a historical or zero-allocation refund has
 * none at all).
 *
 * Deliberately no updated_at (created_at only): this table has no update
 * path to timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refund_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_refund_id')
                ->constrained('payment_refunds')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('payment_allocation_id')
                ->constrained('payment_allocations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['payment_refund_id', 'payment_allocation_id']);
            $table->index('payment_refund_id');
            $table->index('payment_allocation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refund_allocations');
    }
};
