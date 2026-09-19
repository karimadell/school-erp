<?php

namespace Tests;

use App\Models\EnrollmentMode;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function ensureCanonicalRegistrationModeCatalog(): void
    {
        foreach ([
            EnrollmentMode::FULL_TIME => ['Очная форма', true],
            EnrollmentMode::FAMILY => ['Семейная форма', false],
            EnrollmentMode::EXTERNAL => ['Экстернат', false],
            EnrollmentMode::NO_ENROLLMENT => ['Без зачисления', false],
        ] as $code => [$name, $active]) {
            EnrollmentMode::firstOrCreate(
                ['code' => $code],
                ['name_ru' => $name, 'is_active' => $active],
            );
        }
    }
}
