<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();

            $table->string('name_ru');
            $table->string('name_ar')->nullable();

            // breakfast | lunch | both
            $table->enum('meal_type', ['breakfast', 'lunch', 'both'])->index();

            // daily | weekly | monthly | yearly
            $table->enum('period', ['daily', 'weekly', 'monthly', 'yearly'])->index();

            $table->decimal('price', 12, 2)->default(0);

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index(['meal_type', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plans');
    }
};