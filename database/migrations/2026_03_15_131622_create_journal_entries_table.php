<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('journal_entries', function (Blueprint $table) {

            $table->id();

            $table->string('entry_number')->unique();

            $table->date('entry_date');

            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('description')->nullable();

            $table->unsignedBigInteger('created_by');

            $table->timestamps();

        });
    }

    public function down()
    {
        Schema::dropIfExists('journal_entries');
    }
};