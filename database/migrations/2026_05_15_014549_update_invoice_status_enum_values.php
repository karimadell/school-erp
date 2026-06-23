<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE invoices
            MODIFY status ENUM('unpaid', 'partial', 'paid', 'cancelled')
            NOT NULL DEFAULT 'unpaid'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE invoices
            MODIFY status ENUM('unpaid', 'paid', 'cancelled')
            NOT NULL DEFAULT 'unpaid'
        ");
    }
};