<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->date('effective_from');
            $table->date('effective_to');
            $table->text('notes')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->unsignedTinyInteger('published_slot')->nullable();
            $table->timestamps();

            $table->unique(['academic_year_id', 'published_slot'], 'timetable_versions_one_published');
            $table->index(['academic_year_id', 'status'], 'timetable_versions_year_status_index');
            $table->index(['academic_year_id', 'effective_from', 'effective_to'], 'timetable_versions_effective_index');
        });

        Schema::create('academic_timetables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_version_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['timetable_version_id', 'name'], 'academic_timetables_version_name_index');
        });

        Schema::create('timetable_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_timetable_id')->constrained('academic_timetables')->restrictOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->foreignId('bell_schedule_id')->constrained()->restrictOnDelete();
            $table->foreignId('bell_schedule_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('classroom_id')->constrained('classrooms')->restrictOnDelete();
            $table->foreignId('teacher_assignment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_id')->nullable()->constrained('curricula')->restrictOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->restrictOnDelete();
            $table->timestamps();

            $table->index(['timetable_version_id', 'weekday', 'bell_schedule_period_id'], 'timetable_entries_version_day_period_index');
            $table->index(['class_id', 'weekday', 'bell_schedule_period_id'], 'timetable_entries_class_lookup_index');
            $table->index(['teacher_assignment_id', 'weekday', 'bell_schedule_period_id'], 'timetable_entries_teacher_lookup_index');
            $table->index(['classroom_id', 'weekday', 'bell_schedule_period_id'], 'timetable_entries_room_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_entries');
        Schema::dropIfExists('academic_timetables');
        Schema::dropIfExists('timetable_versions');
    }
};
