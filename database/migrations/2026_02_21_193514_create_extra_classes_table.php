<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extra_classes', function (Blueprint $table) {
            $table->id();

            $table->string('name_ru');
            $table->string('name_ar')->nullable();

            // general | OGE | EGE
            $table->enum('type', ['general', 'OGE', 'EGE'])->index();

            // monthly | course
            $table->enum('period', ['monthly', 'course'])->index();

            $table->decimal('price', 12, 2)->default(0);

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index(['type', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extra_classes');
    }
};