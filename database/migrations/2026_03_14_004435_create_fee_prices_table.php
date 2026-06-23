<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_prices', function (Blueprint $table) {

            $table->id();

            $table->foreignId('fee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('amount', 10, 2);

            // 🔥 الجديد
            $table->string('size')->nullable();   // S, M, L, 8, 10...
            $table->string('item')->nullable();   // tshirt, polo, jacket, full_set

            $table->date('start_date');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_prices');
    }
};