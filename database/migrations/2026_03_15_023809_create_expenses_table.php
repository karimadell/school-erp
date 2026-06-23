<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {

            $table->id();

            $table->string('title');

            $table->decimal('amount',10,2);

            $table->string('category')->nullable();

            $table->text('description')->nullable();

            $table->date('expense_date');

            $table->foreignId('cash_account_id')->nullable()->constrained();

            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};