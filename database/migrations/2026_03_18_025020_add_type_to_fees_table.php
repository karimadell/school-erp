<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fees', 'type')) {
            Schema::table('fees', function (Blueprint $table) {
                $table->enum('type', ['monthly', 'yearly', 'service'])
                    ->default('service')
                    ->after('name_ru');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fees', 'type')) {
            Schema::table('fees', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
    }
};