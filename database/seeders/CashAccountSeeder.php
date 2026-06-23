<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CashAccountSeeder extends Seeder
{
    public function run(): void
    {
        // تنظيف الجدول (عشان SQLite)
        DB::table('cash_accounts')->delete();

        DB::table('cash_accounts')->insert([
            [
                'name' => 'Main Cash',
                'type' => 'main',
                'balance' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}