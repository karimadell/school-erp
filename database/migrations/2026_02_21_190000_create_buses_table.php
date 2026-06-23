<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations
     */
    public function up(): void
    {
        Schema::create('buses', function (Blueprint $table) {

            $table->id();

            $table->string('plate_number')->nullable(); // رقم الباص
            $table->string('driver_name')->nullable();  // اسم السائق
            $table->integer('capacity')->default(30);   // عدد المقاعد
            $table->boolean('is_active')->default(true);

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        Schema::dropIfExists('buses');
    }
};