<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selected classes for a billing batch. Membership is later resolved through
 * Enrollment for the batch's academic year — never the stale students.class_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_batch_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_batch_id')->constrained('billing_batches')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->timestamps();

            // Composite uniqueness prevents the same class being targeted twice.
            $table->unique(['billing_batch_id', 'class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_batch_classes');
    }
};
