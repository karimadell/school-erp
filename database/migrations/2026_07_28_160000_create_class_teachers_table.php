<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 11 / C4: Class Teacher assignment (классный руководитель) —
 * SchoolClass x Teacher x Academic Year. Deliberately a dedicated table,
 * not an extension of TeacherAssignment (teacher x class x subject x
 * year) — a homeroom assignment has no subject dimension, and forcing
 * it into TeacherAssignment via a nullable subject_id would reintroduce
 * the exact NULL-uniqueness bug this project already fixed once for
 * student_grades (roadmap item A2): NULL never equals NULL in a unique
 * index, so nullable subject_id rows would silently bypass the
 * one-homeroom-per-class-per-year constraint.
 *
 * `classes` itself carries no academic_year_id (confirmed — a
 * SchoolClass row is a permanent entity, not re-created per year), so
 * the year dimension lives entirely in this join table, mirroring how
 * TeacherAssignment already solves the same problem for subject
 * teaching.
 *
 * Does not implement ResolvesAcademicYear — historical-year lock
 * integration is explicitly deferred, matching Curriculum's precedent
 * (Item 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_teachers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('school_class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // One homeroom teacher per class per year — the same teacher
            // may still be homeroom for multiple different classes.
            $table->unique(['school_class_id', 'academic_year_id'], 'class_teachers_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_teachers');
    }
};
