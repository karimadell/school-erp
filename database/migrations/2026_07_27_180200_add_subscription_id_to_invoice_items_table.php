<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 5: traces an invoice line back to the subscription that generated
 * it. Nullable — manually-added invoice lines (not tied to a subscription)
 * remain valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_items', 'subscription_id')) {
                $table->foreignId('subscription_id')
                    ->nullable()
                    ->after('fee_id')
                    ->constrained('student_service_subscriptions')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_items', 'subscription_id')) {
                $table->dropConstrainedForeignId('subscription_id');
            }
        });
    }
};
