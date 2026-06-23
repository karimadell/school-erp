<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_grades', function (Blueprint $table) {
            if (!Schema::hasColumn('student_grades', 'student_id')) {
                $table->foreignId('student_id')->nullable()->after('id');
            }

            if (!Schema::hasColumn('student_grades', 'subject_id')) {
                $table->foreignId('subject_id')->nullable()->after('student_id');
            }

            if (!Schema::hasColumn('student_grades', 'exam_id')) {
                $table->foreignId('exam_id')->nullable()->after('subject_id');
            }

            if (!Schema::hasColumn('student_grades', 'quarter_id')) {
                $table->foreignId('quarter_id')->nullable()->after('exam_id');
            }

            if (!Schema::hasColumn('student_grades', 'score')) {
                $table->decimal('score', 5, 2)->default(0)->after('quarter_id');
            }

            if (!Schema::hasColumn('student_grades', 'note')) {
                $table->string('note')->nullable()->after('score');
            }
        });

        // unique index only if missing
        try {
            DB::statement('CREATE UNIQUE INDEX unique_student_grade ON student_grades (student_id, subject_id, exam_id, quarter_id)');
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        Schema::table('student_grades', function (Blueprint $table) {
            try {
                $table->dropUnique('unique_student_grade');
            } catch (\Throwable $e) {
            }

            $columnsToDrop = [];

            foreach (['note', 'score', 'quarter_id', 'exam_id', 'subject_id', 'student_id'] as $column) {
                if (Schema::hasColumn('student_grades', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};