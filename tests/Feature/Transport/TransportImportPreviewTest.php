<?php

namespace Tests\Feature\Transport;

use App\Services\Transport\TransportImportPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class TransportImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_preserves_source_values_classifies_staff_and_parses_weekdays(): void
    {
        $path = $this->workbook([
            ['№', 'ФИО', 'Класс', 'Остановка', 'Телефон', 'Примечание'],
            [1, '  Иванов Иван  ', '1 А', ' Каусер, у школы ', '+20123', ''],
            [2, 'Петров Пётр сотр (пн, чт)', 'сотр', 'Главный вход', '+20456', 'дежурство'],
            [3, '', '', 'Неизвестная точка', '', 'без ФИО'],
        ]);

        $service = app(TransportImportPreviewService::class);
        $rows = $service->parseWorkbook($path);

        $this->assertCount(3, $rows);
        $this->assertSame('  Иванов Иван  ', $rows[0]['raw_full_name']);
        $this->assertSame(' Каусер, у школы ', $rows[0]['raw_pickup_point']);
        $this->assertSame('STAFF', $service->classify($rows[1]));
        $this->assertSame('UNKNOWN', $service->classify($rows[2]));
        $method = (new \ReflectionClass($service))->getMethod('weekdays');
        $method->setAccessible(true);
        $this->assertSame(['monday', 'thursday'], $method->invoke($service, $rows[1]['raw_notes'].' '.$rows[1]['raw_full_name']));
    }

    public function test_preview_is_read_only_and_staff_does_not_consume_capacity(): void
    {
        $values = [['№', 'ФИО', 'Класс', 'Остановка', 'Телефон']];
        foreach (range(1, 14) as $number) {
            $values[] = [$number, "Ученик {$number}", '1 А', 'Точка', '', ''];
        }
        $values[] = [15, 'Сотрудник сотр (чт)', 'сотр', 'Точка', '', ''];
        $path = $this->workbook($values);
        $tables = ['students', 'enrollments', 'users', 'buses', 'transport_routes', 'student_transport_assignments', 'vehicle_staff_assignments', 'invoices', 'invoice_items', 'invoice_payments', 'fee_prices'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();

        $result = app(TransportImportPreviewService::class)->preview([$path]);

        $after = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
        $capacity = collect($result['capacity'])->first();
        $this->assertTrue($result['read_only']);
        $this->assertSame($before, $after);
        $this->assertSame(14, $capacity['students']);
        $this->assertSame(1, $capacity['staff']);
        $this->assertSame('FULL', $capacity['status']);
        $this->assertSame(0, $capacity['matched_staff']);
        $this->assertSame(0, $capacity['matched_students']);
    }

    public function test_preview_flags_more_than_fourteen_students_over_capacity(): void
    {
        $values = [['№', 'ФИО', 'Класс', 'Остановка', 'Телефон', 'Примечание']];
        foreach (range(1, 15) as $number) {
            $values[] = [$number, "Ученик {$number}", '1 А', 'Точка', '', ''];
        }
        $result = app(TransportImportPreviewService::class)->preview([$this->workbook($values)]);

        $this->assertSame('OVER_CAPACITY', collect($result['capacity'])->first()['status']);
    }

    private function workbook(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'transport-preview-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
