<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('grades')) {

            Schema::create('grades', function (Blueprint $table) {
                $table->id();

                $table->foreignId('student_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->foreignId('subject_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->foreignId('exam_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->foreignId('term_id')
                    ->constrained()
                    ->cascadeOnDelete();

                // Russian grading system: 5,4,3,2
                $table->integer('grade');

                $table->text('comment')->nullable();

                $table->timestamps();
            });

        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
