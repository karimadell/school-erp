<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('quarters', function (Blueprint $table) {

            $table->id();

            // اسم الربع
            $table->string('name'); // Q1 Q2 Q3 Q4

            // ترتيب الربع
            $table->unsignedTinyInteger('order');

            // تاريخ البداية والنهاية (مفيد للتقارير)
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quarters');
    }

};