<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Schema Builder's enum()->change() compiles to the equivalent
        // "MODIFY status ENUM(...)" on MySQL, and to a safe table rebuild
        // (preserving data) that widens the CHECK constraint on SQLite —
        // so no raw SQL or driver conditional is needed here.
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('status', ['unpaid', 'partial', 'paid', 'cancelled'])
                ->default('unpaid')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('status', ['unpaid', 'paid', 'cancelled'])
                ->default('unpaid')
                ->change();
        });
    }
};