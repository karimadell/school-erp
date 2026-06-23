<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('student_files', function (Blueprint $table) {

            $table->id();

            $table->foreignId('student_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('title');

            $table->string('file_name');

            $table->string('file_path');

            $table->string('file_type');

            $table->unsignedBigInteger('file_size');

            $table->enum('category', [
                'id',
                'contract',
                'report',
                'other'
            ])->default('other');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_files');
    }
};