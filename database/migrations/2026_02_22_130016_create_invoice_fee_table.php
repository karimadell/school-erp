<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoice_fee', function (Blueprint $table) {
            $table->id();

            // invoice
            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->cascadeOnDelete();

            // fee
            $table->foreignId('fee_id')
                ->constrained('fees')
                ->restrictOnDelete();

            // price at time of invoice
            $table->decimal('amount', 10, 2);

            $table->timestamps();

            // prevent duplicate fee in same invoice
            $table->unique(['invoice_id', 'fee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_fee');
    }
};