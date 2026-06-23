<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            if (!Schema::hasColumn('enrollments', 'student_id')) {
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            }

            if (!Schema::hasColumn('enrollments', 'stage_id')) {
                $table->foreignId('stage_id')->nullable()->constrained('stages')->nullOnDelete();
            }

            if (!Schema::hasColumn('enrollments', 'grade_id')) {
                $table->foreignId('grade_id')->nullable()->constrained('grades')->nullOnDelete();
            }

            if (!Schema::hasColumn('enrollments', 'class_id')) {
                $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            }

            if (!Schema::hasColumn('enrollments', 'academic_year')) {
                $table->string('academic_year')->nullable()->after('class_id');
            }

            if (!Schema::hasColumn('enrollments', 'enrollment_date')) {
                $table->date('enrollment_date')->nullable()->after('academic_year');
            }

            if (!Schema::hasColumn('enrollments', 'status')) {
                $table->enum('status', ['active', 'transferred', 'withdrawn', 'graduated'])
                    ->default('active')
                    ->after('enrollment_date');
            }

            if (!Schema::hasColumn('enrollments', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }

            if (!Schema::hasColumn('enrollments', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            //
        });
    }
};