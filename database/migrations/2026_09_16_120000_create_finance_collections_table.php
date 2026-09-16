<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Collection foundation (PR B) — the FinanceCollection aggregate.
 *
 * ONE FinanceCollection represents ONE parent-facing collection operation:
 * exactly one Student, exactly one AcademicYear, exactly one payment
 * method, at most one cash account. It groups whatever InvoicePayment rows
 * FinanceCollectionService records inside its own outer transaction
 * (existing obligations, newly-issued charges, or both) under one shared
 * receipt identity — mirroring the pattern quick_registration_operations
 * already uses for Quick Registration (idempotency_key UNIQUE + a small
 * pending/completed/failed status), folded into a single table since this
 * aggregate IS its own receipt, not a separate operation-tracking row
 * pointing at a separate receipt concept.
 *
 * Deliberately NOT stored here: any authoritative money total. The amount
 * actually received is always derived as SUM(invoice_payments.amount)
 * WHERE finance_collection_id = this row — see FinanceCollection's own
 * receivedTotal()/getReceivedTotalAttribute(). Duplicating that as a
 * mutable column here would create a second, driftable source of truth for
 * the exact number InvoicePaymentService::record() already owns
 * authoritatively per payment row.
 *
 * collection_number mirrors invoice_number/payment_number's own nullable-
 * then-generated-after-persist pattern (see FinanceCollection::booted()) —
 * it is a display identity only, never the idempotency identity.
 * idempotency_key is that identity, exactly like invoice_payments/
 * quick_registration_operations already establish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_collections', function (Blueprint $table) {
            $table->id();

            $table->uuid('idempotency_key')->unique();
            $table->string('payload_hash', 64);

            $table->string('collection_number')->nullable()->unique();

            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('payment_method');
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->nullOnDelete();

            $table->text('notes')->nullable();

            // Intentionally small — see this migration's own docblock and
            // FinanceCollectionService's docblock for why 'failed' is
            // defined but never actually written by the atomic path today
            // (a rolled-back outer transaction leaves no row at all,
            // exactly like quick_registration_operations already
            // guarantees for the same reason).
            $table->string('status', 20)->default('pending');

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Student/year collection history — "every collection for this
            // student in this year," the natural receipt-list query.
            $table->index(['student_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_collections');
    }
};
