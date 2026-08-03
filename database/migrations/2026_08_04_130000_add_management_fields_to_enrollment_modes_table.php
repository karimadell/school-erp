<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_modes', function (Blueprint $table) {
            $table->string('short_name_ru')->nullable()->after('name_ru');
            $table->unsignedInteger('display_order')->default(0)->after('is_active');
            $table->text('description')->nullable()->after('display_order');
        });
    }

    public function down(): void
    {
        Schema::table('enrollment_modes', fn (Blueprint $table) => $table->dropColumn([
            'short_name_ru', 'display_order', 'description',
        ]));
    }
};
