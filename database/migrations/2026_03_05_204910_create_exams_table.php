<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {

            $table->id();

            $table->string('name'); 
            // Exam 1 - Midterm - Final

            $table->foreignId('subject_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('class_id')
                ->constrained('classes')
                ->cascadeOnDelete();

            $table->date('exam_date')->nullable();

            $table->integer('max_score')->default(100);

            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};