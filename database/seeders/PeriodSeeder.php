<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Period;

class PeriodSeeder extends Seeder
{
    public function run(): void
    {

        $periods = [
            [1, '08:00', '08:45'],
            [2, '08:50', '09:35'],
            [3, '09:40', '10:25'],
            [4, '10:30', '11:15'],
            [5, '11:20', '12:05'],
            [6, '12:10', '12:55'],
            [7, '13:00', '13:45'],
        ];

        foreach ($periods as [$number, $start, $end]) {

            Period::firstOrCreate(
                ['number' => $number],
                ['start_time' => $start, 'end_time' => $end]
            );
        }
    }
}
