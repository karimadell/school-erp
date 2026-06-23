<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transfers', function (Blueprint $table) {
            if (!Schema::hasColumn('cash_transfers', 'transfer_date')) {
                $table->date('transfer_date')->nullable()->after('notes');
            }

            if (!Schema::hasColumn('cash_transfers', 'created_by')) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('transfer_date')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('cash_transfers', function (Blueprint $table) {
            if (Schema::hasColumn('cash_transfers', 'created_by')) {
                try {
                    $table->dropForeign(['created_by']);
                } catch (\Throwable $e) {
                }

                $table->dropColumn('created_by');
            }

            if (Schema::hasColumn('cash_transfers', 'transfer_date')) {
                $table->dropColumn('transfer_date');
            }
        });
    }
};