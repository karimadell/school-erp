<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColorToSubjectsTable extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {

            $table->string('color')->nullable();

        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {

            $table->dropColumn('color');

        });
    }
}