<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 5, policy decision 4: overdue-balance blocking must support
 * service-specific exceptions (meals, and any future service) "without
 * schema changes." A boolean flag per Fee is the mechanism — marking any
 * additional fee exempt later is a data change (toggle the flag), never a
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            if (!Schema::hasColumn('fees', 'exempt_from_balance_block')) {
                $table->boolean('exempt_from_balance_block')->default(false)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fees', function (Blueprint $table) {
            if (Schema::hasColumn('fees', 'exempt_from_balance_block')) {
                $table->dropColumn('exempt_from_balance_block');
            }
        });
    }
};
