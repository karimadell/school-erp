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
        Schema::table('teacher_salaries', function (Blueprint $table) {

            $table->decimal('bonus', 10, 2)
                ->default(0)
                ->after('deductions');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teacher_salaries', function (Blueprint $table) {

            $table->dropColumn('bonus');

        });
    }
};