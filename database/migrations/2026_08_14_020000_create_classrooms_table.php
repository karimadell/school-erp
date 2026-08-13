<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('building')->nullable();
            $table->string('floor')->nullable();
            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('capacity');
            $table->string('room_type');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['academic_year_id', 'code'], 'classrooms_academic_year_code_unique');
            $table->index(['academic_year_id', 'is_active'], 'classrooms_year_active_index');
            $table->index(['academic_year_id', 'room_type'], 'classrooms_year_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classrooms');
    }
};
