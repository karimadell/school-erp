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
        Schema::create('bus_routes', function (Blueprint $table) {

            $table->id();

            // اسم الخط
            $table->string('name');

            // وصف الخط
            $table->text('description')->nullable();

            // الباص المرتبط بالخط
            $table->foreignId('bus_id')
                ->nullable()
                ->constrained('buses')
                ->nullOnDelete();

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        Schema::dropIfExists('bus_routes');
    }
};