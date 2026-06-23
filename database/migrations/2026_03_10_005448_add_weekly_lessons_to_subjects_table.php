<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {

            $table->integer('weekly_lessons')->default(3);

        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {

            $table->dropColumn('weekly_lessons');

        });
    }

};