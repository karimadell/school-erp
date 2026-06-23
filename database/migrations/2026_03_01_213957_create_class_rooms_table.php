<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('class_rooms', function (Blueprint $table) {
            $table->id();

            $table->foreignId('grade_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('code');                // A | B | C
            $table->string('name_ar');             // فصل A
            $table->string('name_ru')->nullable(); // Класс A
            $table->integer('capacity')->default(30);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['grade_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_rooms');
    }
};