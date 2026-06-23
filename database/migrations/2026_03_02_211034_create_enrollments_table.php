<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();

            // الطالب
            $table->foreignId('student_id')
                ->constrained()
                ->cascadeOnDelete();

            // السنة الدراسية
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->cascadeOnDelete();

            // Snapshot وقت التسجيل
            $table->foreignId('stage_id')
                ->constrained('stages')
                ->restrictOnDelete();

            $table->foreignId('grade_id')
                ->constrained('grades')
                ->restrictOnDelete();

            $table->foreignId('class_room_id')
                ->constrained('class_rooms')
                ->restrictOnDelete();

            // تاريخ التسجيل
            $table->date('enrolled_at')->default(now());

            // حالة القيد
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // منع تكرار تسجيل نفس الطالب في نفس السنة
            $table->unique(['student_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};