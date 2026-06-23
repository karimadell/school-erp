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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();

            // الفاتورة
            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->cascadeOnDelete();

            // نوع المصروف (مصروفات مدرسية / زي / مطعم ...)
            $table->foreignId('fee_id')
                ->constrained('fees')
                ->cascadeOnDelete();

            // الوصف (اسم الـ Fee وقت إنشاء الفاتورة)
            $table->string('description');

            // المبلغ
            $table->decimal('amount', 12, 2);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};