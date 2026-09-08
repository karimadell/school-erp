<?php

namespace Tests\Feature\Transport;

use App\Models\AcademicYear;
use App\Models\EnrollmentMode;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentBootstrapImport;
use App\Services\Admissions\RealStudentBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class RealStudentBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->structure();
    }

    public function test_preview_plans_64_students_without_writes(): void
    {
        $before = $this->counts();
        $result = app(RealStudentBootstrapService::class)->preview([$this->workbook()]);

        $this->assertSame(64, $result['new_students']);
        $this->assertSame(64, $result['new_enrollments']);
        $this->assertSame($before, $this->counts());
    }

    public function test_apply_keeps_same_name_rows_distinct_and_is_idempotent(): void
    {
        $service = app(RealStudentBootstrapService::class);
        $path = $this->workbook(true);
        $before = $this->counts();

        $first = $service->apply([$path]);
        $second = $service->apply([$path]);

        $this->assertSame(64, $first['created_students']);
        $this->assertSame(0, $second['created_students']);
        $this->assertSame(64, Student::count());
        $this->assertSame(64, DB::table('enrollments')->count());
        $this->assertSame(64, StudentBootstrapImport::count());
        $this->assertSame(2, Student::where('name', 'Денисенко Александра')->count());
        $this->assertSame(2, StudentBootstrapImport::where('raw_full_name', 'Денисенко Александра')->count());
        foreach (['invoices', 'invoice_items', 'invoice_payments', 'fee_prices', 'assignments', 'staff_assignments'] as $key) {
            $this->assertSame($before[$key], $this->counts()[$key]);
        }
    }

    public function test_source_key_is_stable_and_name_can_be_edited_without_changing_import_identity(): void
    {
        $service = app(RealStudentBootstrapService::class);
        $path = $this->workbook(true);
        $first = $service->preview([$path]);
        $second = $service->preview([$path]);
        $this->assertSame(collect($first['rows'])->pluck('source_key')->all(), collect($second['rows'])->pluck('source_key')->all());

        $service->apply([$path]);
        $import = StudentBootstrapImport::firstOrFail();
        $student = $import->student;
        $student->update(['name' => 'Исправленное имя']);
        $this->assertSame('Исправленное имя', $student->fresh()->name);
        $this->assertSame($import->source_key, $import->fresh()->source_key);
    }

    public function test_apply_rolls_back_all_student_enrollment_and_metadata_writes_on_failure(): void
    {
        $before = $this->counts();
        $created = 0;
        Event::listen('eloquent.creating: '.Student::class, function () use (&$created): void {
            $created++;
            if ($created === 2) {
                throw new \RuntimeException('forced bootstrap failure');
            }
        });

        try {
            $this->expectException(\RuntimeException::class);
            app(RealStudentBootstrapService::class)->apply([$this->workbook()]);
        } finally {
            Event::forget('eloquent.creating: '.Student::class);
        }

        $this->assertSame($before, $this->counts());
    }

    private function structure(): void
    {
        AcademicYear::forceCreate(['id' => 1, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        EnrollmentMode::forceCreate(['id' => 1, 'code' => 'full_time', 'name_ru' => 'Очная форма обучения', 'is_active' => true, 'display_order' => 0]);
        foreach ([1 => 'Подготовительная группа', 2 => 'Начальная школа', 3 => 'Основная школа', 4 => 'Старшая школа'] as $id => $name) {
            Stage::forceCreate(['id' => $id, 'name' => $name, 'order' => $id, 'is_active' => true]);
        }
        $grades = [3 => [1, 0], 4 => [2, 1], 5 => [2, 2], 6 => [2, 3], 7 => [2, 4], 8 => [3, 5], 9 => [3, 6], 10 => [3, 7], 11 => [3, 8], 12 => [3, 9], 13 => [4, 10], 2 => [4, 11]];
        $classes = [3 => [3, '0-A'], 4 => [4, '1-А'], 5 => [5, '2_А'], 6 => [6, '3-А'], 7 => [7, '4-А'], 8 => [9, '5-А'], 9 => [10, '6-А'], 10 => [11, '7-А'], 11 => [12, '8-А'], 12 => [13, '9-А'], 13 => [8, '10-А'], 2 => [2, '11-Ф']];
        foreach ($grades as $id => [$stage, $level]) {
            Grade::forceCreate(['id' => $id, 'stage_id' => $stage, 'name' => $level.' класс', 'level' => $level]);
            SchoolClass::forceCreate(['id' => $classes[$id][0], 'grade_id' => $id, 'code' => $classes[$id][1], 'name_ar' => (string) $level, 'name_ru' => $level.' КЛАСС', 'capacity' => 30, 'is_active' => true]);
        }
    }

    private function workbook(bool $duplicate = false): string
    {
        $rows = [['Трансфер Арабия'], ['№', 'ФИО', 'Класс', 'Остановка', 'Телефон', 'Примечание']];
        foreach (range(1, 64) as $n) {
            $name = $duplicate && $n <= 2 ? 'Денисенко Александра' : "Ученик {$n}";
            $rows[] = [$n, $name, (string) (($n - 1) % 12), 'Точка', 'Контакт '.$n, ''];
        }
        foreach (range(1, 11) as $n) {
            $rows[] = [64 + $n, "Сотрудник {$n}", 'сотр', 'Точка', '', ''];
        }
        $rows[] = ['', '', '', '', '', ''];
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'student-bootstrap-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function counts(): array
    {
        return [
            'students' => Student::count(),
            'enrollments' => DB::table('enrollments')->count(),
            'imports' => StudentBootstrapImport::count(),
            'invoices' => DB::table('invoices')->count(),
            'invoice_items' => DB::table('invoice_items')->count(),
            'invoice_payments' => DB::table('invoice_payments')->count(),
            'fee_prices' => DB::table('fee_prices')->count(),
            'assignments' => DB::table('student_transport_assignments')->count(),
            'staff_assignments' => DB::table('vehicle_staff_assignments')->count(),
        ];
    }
}
