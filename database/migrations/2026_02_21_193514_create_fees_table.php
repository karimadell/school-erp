<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('fees', function (Blueprint $table) {
            $table->id();

            // أسماء متعددة اللغات
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('name_ru');

            // نوع الرسوم
            $table->enum('type', ['monthly', 'yearly', 'service'])->default('service');

            // التصنيف (باص - مطعم - دراسي...)
            $table->string('category')->nullable();

            // فترة الدفع
            $table->string('payment_period')->nullable(); // monthly / term / yearly

            // القيمة
            $table->decimal('amount', 10, 2);

            // وصف
            $table->text('description')->nullable();

            // الحالة
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fees');
    }
};