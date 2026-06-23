<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'users',
            'audit_logs',
            'stages',
            'grades',
            'classes',
            'subjects',
            'students',
            'student_grades',
            'exams',
            'fees',
            'invoices',
            'cash_accounts',
            'cash_transactions',
            'cash_transfers',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                if (Schema::hasColumn($table, 'created_at')) {
                    DB::statement("ALTER TABLE `$table` MODIFY `created_at` DATETIME NULL");
                }

                if (Schema::hasColumn($table, 'updated_at')) {
                    DB::statement("ALTER TABLE `$table` MODIFY `updated_at` DATETIME NULL");
                }
            }
        }
    }

    public function down(): void
    {
        // لا نرجعها DATE حتى لا ترجع المشكلة
    }
};