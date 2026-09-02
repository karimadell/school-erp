<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance V2, Phase 2D corrective pass #3 (P0 Blocker 1E — credit
 * application coverage-period linkage).
 *
 * StudentCreditApplication (Phase 2C) is INVOICE-level only — it has no
 * concept of which InvoiceItem(s) a credit reduced at all (confirmed by
 * reading the model/migration directly; unlike PaymentAllocation, which
 * has always been item-level since Phase 1A). Extending credit to
 * settle a SPECIFIC coverage period requires the same item-level step
 * PaymentAllocation already provides for cash — this table is that
 * step, mirroring payment_allocations' own shape exactly.
 *
 * Optional/additive: StudentCreditService::apply() gains an OPTIONAL
 * $allocations parameter (mirroring InvoicePaymentService::record()'s
 * own). Every EXISTING caller that never supplies one (TariffAdjustmentService's
 * credit posting, any manual whole-invoice credit application) keeps
 * working exactly as before — zero rows here, credit stays invoice-level
 * only, and reads as explicitly ambiguous for coverage-settlement
 * purposes (never guessed).
 *
 * Write-once, immutable, append-only — same convention as
 * payment_allocations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_credit_application_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_credit_application_id')
                ->constrained('student_credit_applications')
                ->restrictOnDelete();

            $table->foreignId('invoice_item_id')
                ->constrained('invoice_items')
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            $table->timestamp('created_at')->useCurrent();

            $table->index('student_credit_application_id');
            $table->index('invoice_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_credit_application_items');
    }
};
