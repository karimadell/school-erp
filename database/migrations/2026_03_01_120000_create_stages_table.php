<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stages', function (Blueprint $table) {

            $table->id();

            $table->string('name'); 
            // Example: Primary School

            $table->text('description')->nullable();

            $table->integer('order')->default(1);
            // ترتيب المرحلة

            $table->boolean('is_active')->default(true);
            // تفعيل / تعطيل المرحلة

            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stages');
    }
};