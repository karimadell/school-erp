<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ru');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_plan_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_plan_id')->constrained()->cascadeOnDelete();
            $table->string('name_ru');
            $table->unsignedInteger('sequence');
            $table->unsignedInteger('offset_days')->default(0);
            $table->decimal('percentage', 7, 4);
            $table->timestamps();
            $table->unique(['payment_plan_id', 'sequence']);
        });

        Schema::create('invoice_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name_ru');
            $table->unsignedInteger('sequence');
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('remaining_amount', 12, 2);
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->unique(['invoice_id', 'sequence']);
        });

        Schema::table('invoice_payments', function (Blueprint $table): void {
            $table->foreignId('invoice_installment_id')->nullable()->after('invoice_id')
                ->constrained('invoice_installments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('invoice_installment_id'));
        Schema::dropIfExists('invoice_installments');
        Schema::dropIfExists('payment_plan_installments');
        Schema::dropIfExists('payment_plans');
    }
};
