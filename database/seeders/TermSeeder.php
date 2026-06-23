<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TermSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('terms')->insert([
            ['name' => '1 четверть'],
            ['name' => '2 четверть'],
            ['name' => '3 четверть'],
            ['name' => '4 четверть'],
        ]);
    }
}
