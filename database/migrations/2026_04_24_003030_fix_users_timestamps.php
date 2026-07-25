<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL's MODIFY syntax is not supported by SQLite. SQLite is dynamically
        // typed and already stores created_at/updated_at as nullable columns from
        // the original migration, so no equivalent statement is needed there.
        if (Schema::hasTable('users') && DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY created_at DATETIME NULL");
            DB::statement("ALTER TABLE users MODIFY updated_at DATETIME NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY created_at DATE NULL");
            DB::statement("ALTER TABLE users MODIFY updated_at DATE NULL");
        }
    }
};