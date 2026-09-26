<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stolovaya Phase 2 (Employee cash purchases) — the small, dedicated
 * structured record RevenueEntry alone cannot hold (RevenueEntry's only
 * structured "who" is student_id; it has no employee/meal/quantity/
 * unit-price columns — see the Phase 2 discovery report). One row per
 * submitted purchase, immutable once created (see StaffFoodPurchase's own
 * booted() guards), one-to-one with the RevenueEntry it caused.
 *
 * idempotency_key/idempotency_hash mirror invoices.idempotency_key/
 * idempotency_hash exactly (2026_09_02_120000_add_idempotency_to_invoices_
 * table) — but REQUIRED here, not nullable: unlike Invoice (where most
 * historical callers never pass a key), every Employee Stolovaya
 * submission is expected to carry one (the create form always renders a
 * fresh UUID), so there is no legacy/optional caller to stay compatible
 * with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_food_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revenue_entry_id')->unique()->constrained('revenue_entries');
            $table->foreignId('employee_user_id')->constrained('users');
            $table->foreignId('fee_id')->constrained('fees');
            $table->foreignId('fee_price_id')->constrained('fee_prices');
            $table->foreignId('meal_plan_id')->constrained('meal_plans');
            $table->string('option_value');
            $table->date('food_date');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_amount', 10, 2);
            $table->uuid('idempotency_key')->unique();
            $table->string('idempotency_hash', 64);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['employee_user_id', 'food_date']);
            $table->index(['meal_plan_id', 'food_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_food_purchases');
    }
};
