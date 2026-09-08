<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->string('study_attendance_mode', 20)->nullable()->after('enrollment_mode_id');
        });
        Schema::create('master_student_imports', function (Blueprint $table): void {
            $table->id();
            $table->char('source_key', 64)->unique();
            $table->string('source_file');
            $table->string('source_sheet');
            $table->unsignedInteger('source_row');
            $table->string('raw_name');
            $table->string('raw_class_group');
            $table->string('attendance_marker', 20)->nullable();
            $table->string('resolution_status', 30);
            $table->text('resolution_evidence')->nullable();
            $table->json('source_data');
            $table->foreignId('student_id')->nullable()->constrained('students')->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('staff_master_imports', function (Blueprint $table): void {
            $table->id();
            $table->char('source_key', 64)->unique();
            $table->string('source_file');
            $table->string('source_sheet');
            $table->unsignedInteger('source_row');
            $table->string('raw_name');
            $table->string('position')->nullable();
            $table->text('raw_contact')->nullable();
            $table->text('raw_birth_date')->nullable();
            $table->json('source_data');
            $table->foreignId('staff_member_id')->nullable()->constrained('staff_members')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_master_imports');
        Schema::dropIfExists('master_student_imports');
        Schema::table('enrollments', fn (Blueprint $table) => $table->dropColumn('study_attendance_mode'));
    }
};
