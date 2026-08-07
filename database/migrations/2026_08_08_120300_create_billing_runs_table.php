<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single execution attempt of a billing batch. The table is created now for
 * the approved schema, but no run is written until Checkpoint 3 implements
 * transactional invoice execution — Checkpoint 2 only previews.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_batch_id')->constrained('billing_batches')->cascadeOnDelete();

            // Idempotency key for the execution attempt itself.
            $table->uuid('uuid')->unique();

            // pending → processing → completed / failed
            $table->string('status')->default('pending');

            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->decimal('total_amount', 12, 2)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_runs');
    }
};
