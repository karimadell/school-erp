<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'first_name_ru')) {
                $table->string('first_name_ru')->nullable();
            }

            if (!Schema::hasColumn('students', 'last_name_ru')) {
                $table->string('last_name_ru')->nullable();
            }

            if (!Schema::hasColumn('students', 'patronymic_ru')) {
                $table->string('patronymic_ru')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $columnsToDrop = [];

            foreach (['first_name_ru', 'last_name_ru', 'patronymic_ru'] as $column) {
                if (Schema::hasColumn('students', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};