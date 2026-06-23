<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetables', function (Blueprint $table) {

            $table->id();

            $table->foreignId('class_id')
                ->constrained('classes')
                ->cascadeOnDelete();

            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->cascadeOnDelete();

            $table->foreignId('teacher_id')
                ->constrained('teachers')
                ->cascadeOnDelete();

            $table->foreignId('day_id')
                ->constrained('days')
                ->cascadeOnDelete();

            $table->foreignId('period_id')
                ->constrained('periods')
                ->cascadeOnDelete();

            $table->string('room')->nullable();

            $table->timestamps();

            // منع تكرار الحصة للفصل
            $table->unique([
                'class_id',
                'day_id',
                'period_id'
            ]);

            // منع تضارب المدرسين
            $table->unique([
                'teacher_id',
                'day_id',
                'period_id'
            ]);

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetables');
    }
};