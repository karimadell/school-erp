<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {

            // المرحلة الدراسية
            $table->foreignId('stage_id')
                ->nullable()
                ->constrained('stages')
                ->nullOnDelete();

            // الصف
            $table->foreignId('grade_id')
                ->nullable()
                ->constrained('grades')
                ->nullOnDelete();

            // الفصل
            $table->foreignId('class_room_id')
                ->nullable()
                ->constrained('class_rooms')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {

            $table->dropForeign(['class_room_id']);
            $table->dropForeign(['grade_id']);
            $table->dropForeign(['stage_id']);

            $table->dropColumn([
                'class_room_id',
                'grade_id',
                'stage_id',
            ]);
        });
    }
};