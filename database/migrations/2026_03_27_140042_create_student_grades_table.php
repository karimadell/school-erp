<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('student_grades')) {
            Schema::create('student_grades', function (Blueprint $table) {
                $table->id();

                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
                $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
                $table->foreignId('quarter_id')->nullable()->constrained()->nullOnDelete();

                $table->decimal('score', 5, 2);
                $table->string('note')->nullable();

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('student_grades')) {
            Schema::dropIfExists('student_grades');
        }
    }
};