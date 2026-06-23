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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // student (required logically)
            $table->foreignId('student_id')
                ->constrained('students')
                ->restrictOnDelete();

            // optional readable name (snapshot)
            $table->string('customer_name')->nullable();

            // total invoice amount
            $table->decimal('total_amount', 10, 2);

            // paid only (no unpaid logic in system)
            $table->enum('status', ['paid'])->default('paid');

            // cash / bank account
            $table->foreignId('cash_account_id')
                ->constrained('cash_accounts')
                ->restrictOnDelete();

            // payment datetime
            $table->timestamp('paid_at');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};