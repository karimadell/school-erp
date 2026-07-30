<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\FinancePolicySetting;

/**
 * Batch 5, policy decision 4: a single-row settings table for the two
 * admin-configurable overdue-blocking thresholds. Both nullable — null
 * means that particular threshold is off (no blocking on that dimension).
 * A single row (id = 1) rather than a key/value table: exactly two related
 * settings today, both meaningfully read together: no need for arbitrary
 * key/value generality that nothing else in this batch requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_policy_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('overdue_block_threshold_amount', 10, 2)->nullable();
            $table->unsignedInteger('overdue_block_threshold_days')->nullable();
            $table->timestamps();
        });

        FinancePolicySetting::create(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_policy_settings');
    }
};
