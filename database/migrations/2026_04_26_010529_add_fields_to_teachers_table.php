<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('patronymic')->nullable()->after('first_name'); // اسم الأب (روسي)
            $table->string('specialization')->nullable()->after('email');  // التخصص
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn(['patronymic', 'specialization']);
        });
    }
};