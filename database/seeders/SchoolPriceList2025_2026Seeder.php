<?php

namespace Database\Seeders;

use App\Services\Finance\SchoolPriceListImportService;
use Illuminate\Database\Seeder;
use RuntimeException;

class SchoolPriceList2025_2026Seeder extends Seeder
{
    public function run(): void
    {
        $result = app(SchoolPriceListImportService::class)->import();

        if ($result['conflicts'] !== []) {
            throw new RuntimeException('Импорт прайс-листа завершён с конфликтами: '.implode(' ', $result['conflicts']));
        }
    }
}
