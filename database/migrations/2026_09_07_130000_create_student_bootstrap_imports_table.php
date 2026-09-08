<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_bootstrap_imports', function (Blueprint $table) {
            $table->id();
            $table->char('source_key', 64)->unique();
            $table->string('source_file');
            $table->string('source_sheet');
            $table->unsignedInteger('source_row');
            $table->string('raw_full_name');
            $table->string('raw_class');
            $table->text('raw_pickup_point')->nullable();
            $table->text('raw_contact')->nullable();
            $table->string('route');
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_bootstrap_imports');
    }
};
