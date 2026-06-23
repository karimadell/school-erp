<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fees', function (Blueprint $table) {

            $table->enum('billing_period',[
                'one_time',
                'daily',
                'weekly',
                'monthly',
                'quarterly',
                'yearly'
            ])->default('one_time');

            $table->integer('base_price')->nullable();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fees', function (Blueprint $table) {

            $table->dropColumn('billing_period');
            $table->dropColumn('base_price');

        });
    }
};
