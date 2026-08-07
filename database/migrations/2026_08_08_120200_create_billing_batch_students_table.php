<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit per-student overrides for a billing batch: 'include' forces a
 * student into the resolved set, 'exclude' removes one. Both are merged with
 * the class-based selection during target resolution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_batch_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_batch_id')->constrained('billing_batches')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            // 'include' | 'exclude'
            $table->string('mode');

            $table->timestamps();

            // Composite uniqueness prevents duplicate include/exclude targets.
            $table->unique(['billing_batch_id', 'student_id', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_batch_students');
    }
};
