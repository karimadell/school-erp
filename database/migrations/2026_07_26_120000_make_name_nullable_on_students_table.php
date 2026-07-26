<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * students.name was created NOT NULL, but StudentController::store()/
     * update() never populate it (only first_name_ru/last_name_ru/etc.,
     * not in Student::$fillable either) — the RU name fields superseded
     * it. Student::getFullNameAttribute()/getShortNameAttribute() already
     * treat an empty name as normal and fall back to the RU fields, so
     * name is legacy/optional in practice; the column just never caught
     * up. Every student insert failed with a NOT NULL constraint
     * violation as a result.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });
    }
};
