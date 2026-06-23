<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $table) {

            $table->id();

            $table->foreignId('stage_id')
                ->constrained('stages')
                ->cascadeOnDelete();

            $table->string('name'); 
            // مثال: Grade 1

            $table->integer('level')->nullable();
            // ترتيب الصف

            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};