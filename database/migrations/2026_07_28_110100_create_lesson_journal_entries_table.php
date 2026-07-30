<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 9: real lesson journal for the Teacher Portal, replacing the
 * previous read-only student roster. Named lesson_journal_entries (not
 * journal_entries) to avoid any future collision with the unrelated,
 * still-nonexistent finance double-entry Journal/Account subsystem
 * referenced elsewhere in the codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_journal_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->string('title');
            $table->text('notes')->nullable();
            $table->text('homework')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_journal_entries');
    }
};
