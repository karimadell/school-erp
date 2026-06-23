<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_prices', function (Blueprint $table) {
            if (! Schema::hasColumn('fee_prices', 'grade_group')) {
                $table->string('grade_group')->nullable()->after('grade_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fee_prices', function (Blueprint $table) {
            if (Schema::hasColumn('fee_prices', 'grade_group')) {
                $table->dropColumn('grade_group');
            }
        });
    }
};