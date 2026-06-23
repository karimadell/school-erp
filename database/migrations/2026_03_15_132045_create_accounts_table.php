<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('accounts', function (Blueprint $table) {

            $table->id();

            $table->string('code')->unique();
            $table->string('name');

            $table->enum('type',[
                'asset',
                'liability',
                'equity',
                'income',
                'expense'
            ]);

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('accounts')
                ->cascadeOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

        });
    }

    public function down()
    {
        Schema::dropIfExists('accounts');
    }
};