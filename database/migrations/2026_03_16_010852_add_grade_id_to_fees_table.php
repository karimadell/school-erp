<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('fees', function (Blueprint $table) {

            $table->foreignId('grade_id')
                ->nullable()
                ->after('category')
                ->constrained('grades')
                ->nullOnDelete();

        });
    }

    public function down(): void
    {
        Schema::table('fees', function (Blueprint $table) {

            $table->dropConstrainedForeignId('grade_id');

        });
    }
};