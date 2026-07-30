<?php

namespace Database\Seeders;

use App\Models\Day;
use Illuminate\Database\Seeder;

/**
 * D1 Phase 2 (docs/IMPLEMENTATION_READINESS_ROADMAP.md, row D1): `days`
 * had no seeder at all. Seeds the 7 weekday rows, Sunday-first (matching
 * the approved working-week framing: Sunday-Thursday working, Friday +
 * Saturday non-working), keyed on the durable `code` via firstOrCreate
 * so this is safe to re-run.
 */
class DaySeeder extends Seeder
{
    private const DAYS = [
        ['code' => 'sun', 'order' => 0, 'name' => 'Воскресенье'],
        ['code' => 'mon', 'order' => 1, 'name' => 'Понедельник'],
        ['code' => 'tue', 'order' => 2, 'name' => 'Вторник'],
        ['code' => 'wed', 'order' => 3, 'name' => 'Среда'],
        ['code' => 'thu', 'order' => 4, 'name' => 'Четверг'],
        ['code' => 'fri', 'order' => 5, 'name' => 'Пятница'],
        ['code' => 'sat', 'order' => 6, 'name' => 'Суббота'],
    ];

    public function run(): void
    {
        foreach (self::DAYS as $day) {
            Day::firstOrCreate(
                ['code' => $day['code']],
                ['order' => $day['order'], 'name' => $day['name']]
            );
        }
    }
}
