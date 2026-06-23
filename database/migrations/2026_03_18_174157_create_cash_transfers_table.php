<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();

            $table->string('receipt_number')->unique();

            $table->foreignId('from_account_id')->constrained('cash_accounts')->cascadeOnDelete();
            $table->foreignId('to_account_id')->constrained('cash_accounts')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->text('notes')->nullable();

            // 👇 الإضافات الجديدة
            $table->date('transfer_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transfers');
    }
};