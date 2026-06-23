<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_files', function (Blueprint $table) {
            $table->string('type')
                ->after('file_name')
                ->default('other');
            // id | contract | report | other
        });
    }

    public function down(): void
    {
        Schema::table('student_files', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};