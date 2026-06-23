<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            DB::statement("ALTER TABLE audit_logs MODIFY created_at DATETIME NULL");
            DB::statement("ALTER TABLE audit_logs MODIFY updated_at DATETIME NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_logs')) {
            DB::statement("ALTER TABLE audit_logs MODIFY created_at DATE NULL");
            DB::statement("ALTER TABLE audit_logs MODIFY updated_at DATE NULL");
        }
    }
};