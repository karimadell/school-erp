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
        Schema::create('bus_stops', function (Blueprint $table) {

            $table->id();

            // اسم المحطة
            $table->string('name');

            // الخط المرتبطة به
            $table->foreignId('bus_route_id')
                ->constrained('bus_routes')
                ->cascadeOnDelete();

            // ترتيب المحطة في الخط
            $table->integer('stop_order')->default(1);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        Schema::dropIfExists('bus_stops');
    }
};