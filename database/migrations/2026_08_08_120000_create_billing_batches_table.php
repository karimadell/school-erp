<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4D-A Mass Billing (Checkpoint 2): a billing batch is a reusable,
 * previewable definition for issuing one fee/service to many students for a
 * single academic year. It carries the tariff-only issuance parameters (fee,
 * quantity, issue date, due date), the persisted targeting mode, a UUID
 * idempotency key, lifecycle status, and preview counters/snapshot.
 *
 * No invoices are created at this checkpoint — execution lands in Checkpoint 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_batches', function (Blueprint $table) {
            $table->id();

            // Idempotency key: guarantees a batch (and later its run) can be
            // safely retried without duplicating work.
            $table->uuid('uuid')->unique();

            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('fee_id')->constrained('fees')->restrictOnDelete();

            $table->unsignedInteger('quantity')->default(1);
            $table->string('currency', 3)->default('EGP');

            $table->date('issue_date');
            $table->date('due_date');

            $table->string('description')->nullable();

            // Base targeting mode; the concrete class list and explicit
            // include/exclude students live in the target tables.
            $table->string('target_mode')->default('classes');

            // draft → previewed → processing → completed / failed
            $table->string('status')->default('draft');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();

            // Preview result counters and an informational snapshot. These are
            // never trusted by execution (Checkpoint 3 recalculates under locks).
            $table->unsignedInteger('selected_count')->nullable();
            $table->unsignedInteger('eligible_count')->nullable();
            $table->unsignedInteger('skipped_count')->nullable();
            $table->unsignedInteger('expected_invoice_count')->nullable();
            $table->decimal('expected_total_amount', 12, 2)->nullable();
            $table->json('preview_snapshot')->nullable();
            $table->timestamp('previewed_at')->nullable();

            $table->timestamps();

            $table->index(['academic_year_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_batches');
    }
};
