<?php

namespace Database\Seeders;

use App\Models\EnrollmentMode;
use Illuminate\Database\Seeder;

class EnrollmentModeSeeder extends Seeder
{
    /**
     * Canonical operational study modes. code is the only identity used to
     * decide whether a row already exists — never name_ru. An existing row
     * for a canonical code (including any values an operator has since
     * edited in the UI) is never overwritten; only a missing canonical code
     * is created, with exactly these defaults. Any other, non-canonical row
     * already in the table (e.g. a historical test mode) is never touched
     * by this seeder — it neither reads nor writes anything outside the
     * four codes below.
     *
     * family/external/no_enrollment are seeded inactive: they must not
     * become operator-selectable until a later phase deliberately
     * activates them.
     *
     * @var array<int, array<string, mixed>>
     */
    private const CANONICAL_MODES = [
        [
            'code' => EnrollmentMode::FULL_TIME,
            'name_ru' => 'Очная форма обучения',
            'short_name_ru' => 'Очная',
            'display_order' => 0,
            'is_active' => true,
        ],
        [
            'code' => EnrollmentMode::FAMILY,
            'name_ru' => 'Семейная форма обучения',
            'short_name_ru' => 'Семейная',
            'display_order' => 1,
            'is_active' => false,
        ],
        [
            'code' => EnrollmentMode::EXTERNAL,
            'name_ru' => 'Экстернат',
            'short_name_ru' => 'Экстернат',
            'display_order' => 2,
            'is_active' => false,
        ],
        [
            'code' => EnrollmentMode::NO_ENROLLMENT,
            'name_ru' => 'Без зачисления',
            'short_name_ru' => 'Без зачисления',
            'display_order' => 3,
            'is_active' => false,
        ],
    ];

    public function run(): void
    {
        foreach (self::CANONICAL_MODES as $mode) {
            EnrollmentMode::firstOrCreate(['code' => $mode['code']], $mode);
        }
    }
}
