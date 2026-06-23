<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_fee', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_fee', 'item')) {
                $table->string('item')->nullable()->after('amount');
            }

            if (! Schema::hasColumn('invoice_fee', 'size')) {
                $table->string('size')->nullable()->after('item');
            }

            if (! Schema::hasColumn('invoice_fee', 'option_type')) {
                $table->string('option_type')->nullable()->after('size');
            }

            if (! Schema::hasColumn('invoice_fee', 'option_value')) {
                $table->string('option_value')->nullable()->after('option_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_fee', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_fee', 'option_value')) {
                $table->dropColumn('option_value');
            }

            if (Schema::hasColumn('invoice_fee', 'option_type')) {
                $table->dropColumn('option_type');
            }

            if (Schema::hasColumn('invoice_fee', 'size')) {
                $table->dropColumn('size');
            }

            if (Schema::hasColumn('invoice_fee', 'item')) {
                $table->dropColumn('item');
            }
        });
    }
};