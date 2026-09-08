<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->string('preferred_name')->nullable()->after('name');
            $table->foreignId('merged_into_student_id')->nullable()->after('preferred_name')->constrained('students')->restrictOnDelete();
        });
        Schema::create('student_listener_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('stage_id')->constrained('stages')->restrictOnDelete();
            $table->foreignId('grade_id')->constrained('grades')->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->string('source_marker', 20)->default('БЗ');
            $table->string('status', 20)->default('active');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'academic_year_id']);
        });
        Schema::table('master_student_imports', function (Blueprint $table): void {
            $table->foreignId('listener_placement_id')->nullable()->after('enrollment_id')->constrained('student_listener_placements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('master_student_imports', fn (Blueprint $table) => $table->dropConstrainedForeignId('listener_placement_id'));
        Schema::dropIfExists('student_listener_placements');
        Schema::table('students', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merged_into_student_id');
            $table->dropColumn('preferred_name');
        });
    }
};
