<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-student outcome of a billing run. Created now for the approved schema;
 * population and invoice linkage are Checkpoint 3 concerns. invoice_id stays
 * null until an invoice is actually issued for the student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_run_id')->constrained('billing_runs')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            // eligible | skipped | created | failed
            $table->string('status');
            // Stable machine reason when skipped/failed (e.g. no_tariff).
            $table->string('skip_reason')->nullable();

            $table->decimal('unit_price', 12, 2)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->decimal('amount', 12, 2)->nullable();

            $table->timestamps();

            // One outcome row per student within a run.
            $table->unique(['billing_run_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_run_items');
    }
};
