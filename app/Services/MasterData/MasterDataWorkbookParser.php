<?php

namespace App\Services\MasterData;

class MasterDataWorkbookParser
{
    public function __construct(private WorkbookLoader $loader) {}

    public function students(string $path): array
    {
        return $this->loader->remember('master-students', $path, function ($workbook) use ($path): array {
            $rows = [];
            foreach ($workbook->getWorksheetIterator() as $sheet) {
                for ($row = 3; $row <= $sheet->getHighestDataRow(); $row++) {
                    $number = trim((string) $sheet->getCell("A{$row}")->getFormattedValue());
                    $name = trim((string) $sheet->getCell("B{$row}")->getFormattedValue());
                    if (! ctype_digit($number) || $name === '') {
                        continue;
                    }
                    $rawClass = trim((string) $sheet->getCell("C{$row}")->getFormattedValue());
                    $rows[] = ['source_file' => basename($path), 'source_sheet' => $sheet->getTitle(), 'source_row' => $row, 'raw_name' => $name,
                        'raw_class_group' => $rawClass, 'numeric_grade' => $this->grade($rawClass), 'attendance_marker' => $this->marker($rawClass),
                        'parent' => trim((string) $sheet->getCell("D{$row}")->getFormattedValue()), 'egypt_phone' => trim((string) $sheet->getCell("E{$row}")->getFormattedValue()),
                        'messenger_phone' => trim((string) $sheet->getCell("F{$row}")->getFormattedValue()), 'file_number' => trim((string) $sheet->getCell("G{$row}")->getFormattedValue()),
                        'citizenship' => trim((string) $sheet->getCell("H{$row}")->getFormattedValue()), 'notes' => trim((string) $sheet->getCell("I{$row}")->getFormattedValue())];
                }
            }

            return $rows;
        });
    }

    public function staff(string $path): array
    {
        return $this->loader->remember('master-staff', $path, function ($workbook) use ($path): array {
            $rows = [];
            foreach ($workbook->getWorksheetIterator() as $sheet) {
                for ($row = 4; $row <= $sheet->getHighestDataRow(); $row++) {
                    $number = trim((string) $sheet->getCell("A{$row}")->getFormattedValue());
                    $name = trim((string) $sheet->getCell("B{$row}")->getFormattedValue());
                    if (! ctype_digit($number) || $name === '') {
                        continue;
                    }
                    $rows[] = ['source_file' => basename($path), 'source_sheet' => $sheet->getTitle(), 'source_row' => $row, 'raw_name' => $name,
                        'raw_birth_date' => trim((string) $sheet->getCell("F{$row}")->getFormattedValue()), 'position' => trim((string) $sheet->getCell("G{$row}")->getFormattedValue()),
                        'raw_contact' => trim((string) $sheet->getCell("J{$row}")->getFormattedValue())];
                }
            }

            return $rows;
        });
    }

    public function sourceKey(array $row, bool $includeClass = false): string
    {
        $parts = [$row['source_file'], $row['source_sheet'], (int) $row['source_row'], $row['raw_name']];
        if ($includeClass) {
            $parts[] = $row['raw_class_group'];
        }

        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE));
    }

    private function grade(string $value): int
    {
        if (! preg_match('/\d+/', $value, $m)) {
            throw new \InvalidArgumentException("No numeric grade in {$value}.");
        }

        return (int) $m[0];
    }

    private function marker(string $value): ?string
    {
        $key = mb_strtoupper(str_replace([' ', '/'], '', $value));

        return str_contains($key, 'ДО') ? 'ДО' : (str_contains($key, 'БЗ') ? 'БЗ' : null);
    }
}
