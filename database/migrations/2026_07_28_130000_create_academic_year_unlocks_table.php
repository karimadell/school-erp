<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 2 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, B9): temporary,
 * whole-academic-year unlock — approved policy: no per-record/per-module
 * unlocking, no permanent unlock (expires_at is required, never nullable).
 * unlocked_by is nullable + nullOnDelete, matching this project's existing
 * actor-reference convention on audit_logs.user_id, rather than a stricter
 * restrict rule invented just for this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_year_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_year_unlocks');
    }
};
