<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fees', function (Blueprint $table): void {
            $table->boolean('is_non_refundable')->default(false)->after('is_active');
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->boolean('is_non_refundable')->default(false)->after('remaining_amount');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->text('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropColumn('is_non_refundable'));
        Schema::table('fees', fn (Blueprint $table) => $table->dropColumn('is_non_refundable'));
    }
};
