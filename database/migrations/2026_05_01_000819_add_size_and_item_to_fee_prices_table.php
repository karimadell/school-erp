<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_prices', function (Blueprint $table) {
            if (! Schema::hasColumn('fee_prices', 'size')) {
                $table->string('size')->nullable()->after('amount');
            }

            if (! Schema::hasColumn('fee_prices', 'item')) {
                $table->string('item')->nullable()->after('size');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fee_prices', function (Blueprint $table) {
            if (Schema::hasColumn('fee_prices', 'item')) {
                $table->dropColumn('item');
            }

            if (Schema::hasColumn('fee_prices', 'size')) {
                $table->dropColumn('size');
            }
        });
    }
};