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
        Schema::create('transport_subscriptions', function (Blueprint $table) {
            $table->id();

            // الطالب
            $table->foreignId('student_id')
                ->constrained()
                ->cascadeOnDelete();

            // خط الأتوبيس
            $table->foreignId('route_id')
                ->constrained('transport_routes')
                ->cascadeOnDelete();

            // سعر الاشتراك
            $table->decimal('price', 10, 2);

            // حالة الاشتراك
            $table->enum('status', ['active', 'stopped'])
                ->default('active');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transport_subscriptions');
    }
};