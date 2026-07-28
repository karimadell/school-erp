<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Quarter;
use Illuminate\Database\Seeder;

/**
 * Item 3 / B2 (docs/IMPLEMENTATION_READINESS_ROADMAP.md): quarters had no
 * seeder at all, leaving every quarter-dependent screen with an empty
 * dropdown on a fresh install. Seeds the standard 4 quarters for every
 * existing AcademicYear (not just the first one), keyed on
 * (academic_year_id, order) via firstOrCreate so this is safe to re-run —
 * both for re-seeding and for filling in a newly added year later.
 */
class QuarterSeeder extends Seeder
{
    private const QUARTERS = [
        1 => '1 четверть',
        2 => '2 четверть',
        3 => '3 четверть',
        4 => '4 четверть',
    ];

    public function run(): void
    {
        AcademicYear::all()->each(function (AcademicYear $year) {
            foreach (self::QUARTERS as $order => $name) {
                Quarter::firstOrCreate(
                    ['academic_year_id' => $year->id, 'order' => $order],
                    ['name' => $name]
                );
            }
        });
    }
}
