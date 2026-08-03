<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_prices', function (Blueprint $table) {
            $table->foreignId('academic_year_id')->nullable()->after('fee_id')
                ->constrained('academic_years')->restrictOnDelete();
            $table->string('currency', 3)->default('EGP')->after('amount');
            $table->index(['fee_id', 'academic_year_id', 'is_active', 'start_date'], 'fee_prices_resolution_index');
        });

        DB::table('fee_prices')->orderBy('id')->each(function ($price): void {
            $yearId = DB::table('academic_years')
                ->whereDate('start_date', '<=', $price->start_date)
                ->whereDate('end_date', '>=', $price->start_date)
                ->orderByDesc('start_date')->value('id');
            $yearId ??= DB::table('academic_years')->where('is_active', true)->orderByDesc('start_date')->value('id');
            $yearId ??= DB::table('academic_years')->orderByDesc('start_date')->value('id');

            DB::table('fee_prices')->where('id', $price->id)->update([
                'academic_year_id' => $yearId,
                'currency' => 'EGP',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('fee_prices', function (Blueprint $table) {
            $table->dropIndex('fee_prices_resolution_index');
            $table->dropConstrainedForeignId('academic_year_id');
            $table->dropColumn('currency');
        });
    }
};
