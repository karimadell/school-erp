<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('journal_items', function (Blueprint $table) {

            $table->id();

            $table->foreignId('journal_entry_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedBigInteger('account_id');

            $table->decimal('debit',12,2)->default(0);
            $table->decimal('credit',12,2)->default(0);

            $table->text('description')->nullable();

            $table->timestamps();

        });
    }

    public function down()
    {
        Schema::dropIfExists('journal_items');
    }
};