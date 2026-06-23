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
        Schema::table('student_grades', function (Blueprint $table) {

            $table->foreignId('quarter_id')
                ->nullable()
                ->after('exam_id')
                ->constrained('quarters')
                ->cascadeOnDelete();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_grades', function (Blueprint $table) {

            $table->dropForeign(['quarter_id']);
            $table->dropColumn('quarter_id');

        });
    }
};