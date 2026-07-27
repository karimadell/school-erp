<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 5: dated student <-> meal plan link. meal_plans itself already
 * existed (migration only, no model, no consumer) — this builds the
 * missing model layer and the missing student-facing link. end_date
 * nullable = ongoing/current subscription; a plan change or stop is a new
 * row / a closed end_date, never an edit that erases history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->constrained()->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_subscriptions');
    }
};
