<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            DB::statement("ALTER TABLE users MODIFY created_at DATETIME NULL");
            DB::statement("ALTER TABLE users MODIFY updated_at DATETIME NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users')) {
            DB::statement("ALTER TABLE users MODIFY created_at DATE NULL");
            DB::statement("ALTER TABLE users MODIFY updated_at DATE NULL");
        }
    }
};