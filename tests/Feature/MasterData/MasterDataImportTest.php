<?php

namespace Tests\Feature\MasterData;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Bus;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Grade;
use App\Models\MasterStudentImport;
use App\Models\SchoolClass;
use App\Models\StaffMasterImport;
use App\Models\StaffMember;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentBootstrapImport;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\User;
use App\Models\VehicleStaffAssignment;
use App\Services\MasterData\MasterDataWorkbookParser;
use App\Services\MasterData\MasterStaffImportService;
use App\Services\MasterData\MasterStudentImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MasterDataImportTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private string $studentPath;

    private string $staffPath;

    private array $transportPaths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPortableFixtures();
        (new RolesAndPermissionsSeeder)->run();
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole('admin');
        AcademicYear::forceCreate(['id' => 1, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        EnrollmentMode::forceCreate(['id' => 1, 'code' => 'full_time', 'name_ru' => 'Очная', 'is_active' => true, 'display_order' => 0]);
        foreach (range(0, 11) as $level) {
            $stage = Stage::forceCreate(['id' => $level + 1, 'name' => "Stage {$level}", 'order' => $level + 1, 'is_active' => true]);
            $grade = Grade::forceCreate(['id' => $level + 1, 'stage_id' => $stage->id, 'name' => "{$level} класс", 'level' => $level]);
            SchoolClass::forceCreate(['id' => $level + 1, 'grade_id' => $grade->id, 'code' => "{$level}-A", 'name_ru' => "{$level} КЛАСС", 'name_ar' => (string) $level, 'capacity' => 200, 'is_active' => true]);
        }
        foreach ([1 => 'Арабия', 2 => 'Бествэй', 3 => 'Бритиш', 4 => 'Каусер', 5 => 'Эль Ахья'] as $id => $name) {
            TransportRoute::forceCreate(['id' => $id, 'name' => $name, 'is_active' => true]);
            Bus::forceCreate(['id' => $id, 'vehicle_code' => (string) $id, 'transport_route_id' => $id, 'student_capacity' => 14, 'passenger_capacity' => 15, 'is_active' => true]);
        }
        $this->seedExistingTransportPopulation();
    }

    public function test_real_master_preview_parses_exact_counts_and_writes_nothing(): void
    {
        $before = $this->counts();
        $students = app(MasterStudentImportService::class)->preview($this->studentPath);
        $staff = app(MasterStaffImportService::class)->preview($this->staffPath, $this->transportPaths);
        $this->assertSame(134, $students['source_rows']);
        $this->assertSame(71, $students['new_students']);
        $this->assertSame(
            ['Денисенко Александра', 'Эльшейх Адам', 'Эльшейх Сухайб'],
            collect($students['review_required'])->pluck('name')->all()
        );
        $this->assertEqualsCanonicalizing(['БЗ' => 4, 'ДО' => 12], $students['attendance_markers']);
        $this->assertSame('BLINOV_CORRECTION', $students['blinov_correction']['action']);
        $this->assertSame(26, $staff['source_rows']);
        $this->assertSame(26, $staff['new_staff_members']);
        $this->assertSame(8, $staff['transport_links_matched']);
        $this->assertSame(3, $staff['transport_links_review_required']);
        $this->assertSame([1, 4], collect($staff['transport_links'])->firstWhere('display_name', 'Карев Н.А.')['weekdays']);
        $this->assertSame([4], collect($staff['transport_links'])->firstWhere('display_name', 'Щербакова О.В.')['weekdays']);
        $this->assertSame($before, $this->counts());
    }

    public function test_apply_is_transactional_idempotent_and_transport_finance_safe(): void
    {
        $transportHash = hash('sha256', StudentTransportAssignment::orderBy('id')->get()->toJson());
        $finance = $this->finance();
        $first = app(MasterStudentImportService::class)->apply($this->actor, $this->studentPath);
        $staffFirst = app(MasterStaffImportService::class)->apply($this->actor, $this->staffPath, $this->transportPaths);
        $second = app(MasterStudentImportService::class)->apply($this->actor, $this->studentPath);
        $staffSecond = app(MasterStaffImportService::class)->apply($this->actor, $this->staffPath, $this->transportPaths);
        $this->assertSame(71, $first['created_students']);
        $this->assertSame(1, $first['corrected_enrollments']);
        $this->assertSame(0, $second['created_students']);
        $this->assertSame(0, $second['corrected_enrollments']);
        $this->assertSame(26, $staffFirst['created_staff_members']);
        $this->assertSame(0, $staffFirst['created_transport_links']);
        $this->assertSame(0, $staffSecond['created_staff_members']);
        $this->assertCount(11, collect($staffSecond['transport_links'])->whereNotNull('staff_member_id'));
        $this->assertSame(134, MasterStudentImport::count());
        $this->assertSame(26, StaffMasterImport::count());
        $this->assertSame(26, StaffMember::count());
        $this->assertSame(0, StaffMember::whereNotNull('user_id')->count());
        $this->assertSame(12, Enrollment::where('study_attendance_mode', 'ДО')->count());
        $this->assertSame(4, Enrollment::where('study_attendance_mode', 'БЗ')->count());
        $this->assertSame(8, Enrollment::findOrFail(StudentBootstrapImport::where('raw_full_name', 'Блинов Добрыня')->value('enrollment_id'))->grade->level);
        $this->assertSame(64, StudentTransportAssignment::count());
        $this->assertSame($transportHash, hash('sha256', StudentTransportAssignment::orderBy('id')->get()->toJson()));
        $this->assertSame(0, VehicleStaffAssignment::count());
        $this->assertSame($finance, $this->finance());
        $this->assertSame(136, Student::count());
        $this->assertSame(71, AuditLog::where('user_id', $this->actor->id)->where('action', 'master_student_created')->count());
        $this->assertSame(26, AuditLog::where('user_id', $this->actor->id)->where('action', 'master_staff_member_created')->count());
    }

    public function test_student_apply_rolls_back_on_failure(): void
    {
        $before = $this->counts();
        $n = 0;
        Event::listen('eloquent.created: '.MasterStudentImport::class, function () use (&$n) {
            if (++$n === 2) {
                throw new \RuntimeException('forced');
            }
        });
        try {
            app(MasterStudentImportService::class)->apply($this->actor, $this->studentPath);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced', $e->getMessage());
        } finally {
            Event::forget('eloquent.created: '.MasterStudentImport::class);
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_baseline_drift_fails_closed_without_writes(): void
    {
        StudentBootstrapImport::query()->firstOrFail()->delete();
        $before = $this->counts();

        try {
            app(MasterStudentImportService::class)->preview($this->studentPath);
            $this->fail('Expected baseline rejection');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('baseline', $e->errors());
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_staff_apply_rolls_back_on_failure(): void
    {
        $before = $this->counts();
        Event::listen('eloquent.created: '.StaffMasterImport::class, fn () => throw new \RuntimeException('forced staff rollback'));
        try {
            app(MasterStaffImportService::class)->apply($this->actor, $this->staffPath, $this->transportPaths);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced staff rollback', $e->getMessage());
        } finally {
            Event::forget('eloquent.created: '.StaffMasterImport::class);
        }
        $this->assertSame($before, $this->counts());
    }

    private function seedExistingTransportPopulation(): void
    {
        $parser = app(MasterDataWorkbookParser::class);
        $rows = collect($parser->students($this->studentPath));
        $ordinary = $rows->whereNull('attendance_marker')->reject(fn ($r) => in_array($r['raw_name'], ['Блинов Добрыня', 'Денисенко Александра', 'Эльшейх Сухайб'], true))->take(60);
        $sources = $ordinary->map(fn ($r) => ['name' => $r['raw_name'], 'class' => $r['numeric_grade'], 'file' => 'Трансфер_Арабия.xlsx', 'row' => $r['source_row'], 'contact' => ''])->all();
        $sources[] = ['name' => 'Блинов Добрыня', 'class' => 7, 'file' => 'Трансфер_Эль Ахья.xlsx', 'row' => 8, 'contact' => '+79033220262'];
        $sources[] = ['name' => 'Денисенко Александра', 'class' => 10, 'file' => 'Трансфер_Бритиш.xlsx', 'row' => 14, 'contact' => '+380 50 712 6693'];
        $sources[] = ['name' => 'Денисенко Александра', 'class' => 10, 'file' => 'Трансфер_Эль Ахья.xlsx', 'row' => 5, 'contact' => ''];
        $sources[] = ['name' => 'Эльшейх Адам', 'class' => 3, 'file' => 'Трансфер_Каусер.xlsx', 'row' => 17, 'contact' => '+201022376025'];
        foreach ($sources as $i => $s) {
            $student = Student::create(['name' => $s['name'], 'status' => 'active']);
            $grade = Grade::where('level', $s['class'])->firstOrFail();
            $class = SchoolClass::where('grade_id', $grade->id)->firstOrFail();
            $enrollment = Enrollment::create(['student_id' => $student->id, 'academic_year_id' => 1, 'enrollment_mode_id' => 1, 'stage_id' => $grade->stage_id, 'grade_id' => $grade->id, 'class_id' => $class->id, 'academic_year' => '2026/2027', 'enrollment_date' => '2026-09-01', 'enrolled_at' => '2026-09-01', 'status' => 'active', 'is_active' => true]);
            StudentBootstrapImport::create(['source_key' => hash('sha256', $s['file'].'-'.$i), 'source_file' => $s['file'], 'source_sheet' => 'Sheet1', 'source_row' => $s['row'], 'raw_full_name' => $s['name'], 'raw_class' => (string) $s['class'], 'raw_contact' => $s['contact'], 'route' => 'Арабия', 'student_id' => $student->id, 'enrollment_id' => $enrollment->id]);
            StudentTransportAssignment::create(['enrollment_id' => $enrollment->id, 'transport_route_id' => ($i % 5) + 1, 'bus_id' => ($i % 5) + 1, 'effective_from' => '2026-09-01', 'status' => 'active', 'created_by' => $this->actor->id]);
        }
        Student::create(['name' => 'Demo Student', 'status' => 'active']);
    }

    private function counts(): array
    {
        return ['students' => Student::count(), 'enrollments' => Enrollment::count(), 'master_students' => MasterStudentImport::count(), 'staff' => StaffMember::count(), 'master_staff' => StaffMasterImport::count(), 'student_transport' => StudentTransportAssignment::count(), 'staff_transport' => VehicleStaffAssignment::count()] + $this->finance();
    }

    private function finance(): array
    {
        return ['fees' => DB::table('fee_prices')->count(), 'invoices' => DB::table('invoices')->count(), 'items' => DB::table('invoice_items')->count(), 'payments' => DB::table('invoice_payments')->count(), 'subscriptions' => DB::table('student_service_subscriptions')->count()];
    }

    private function buildPortableFixtures(): void
    {
        $dir = sys_get_temp_dir().'/school-master-'.bin2hex(random_bytes(5));
        mkdir($dir, 0777, true);
        $this->studentPath = $dir.'/students.xlsx';
        $this->staffPath = $dir.'/staff.xlsx';

        $studentNames = collect(range(1, 60))->map(fn ($n) => "Ученик {$n}")->all();
        $studentNames = array_merge($studentNames, ['Блинов Добрыня', 'Денисенко Александра', 'Эльшейх Сухайб']);
        while (count($studentNames) < 134) {
            $studentNames[] = 'Новый ученик '.count($studentNames);
        }
        $studentRows = collect($studentNames)->map(function ($name, $i) {
            $class = (string) ($i % 12);
            if ($i >= 118 && $i < 130) {
                $class .= '/ДО';
            } elseif ($i >= 130) {
                $class .= '/БЗ';
            }
            if ($name === 'Блинов Добрыня') {
                $class = '8';
            }

            return [$i + 1, $name, $class];
        })->all();
        $this->writeWorkbook($this->studentPath, [['№', 'ФИО', 'Класс']], $studentRows, 3);

        $full = ['Егорова Ольга Викторовна', 'Герасимович Наталья Ивановна', 'Лебедева Галина Георгиевна', 'Карев Николай Александрович', 'Гринько Марина Дмитриевна', 'Чумакова Виктория Валерьевна', 'Зоценко Светлана Зиновьевна', 'Заморева Марина Михайловна', 'Мазитова Римма Ринатовна', 'Реда', 'Щербакова Ольга Владимировна'];
        while (count($full) < 26) {
            $full[] = 'Сотрудник Имя '.count($full);
        }
        $staffRows = collect($full)->map(fn ($name, $i) => [$i + 1, $name, null, null, null, '01.01.1900', 'Сотрудник', null, null, '+200'.$i])->all();
        $this->writeWorkbook($this->staffPath, [['№', 'ФИО', '', '', '', 'Дата рождения', 'Должность', '', '', 'Телефон']], $staffRows, 4);

        $routes = ['Арабия' => ['Егорова О.В.', 'Герасимович Н.И.'], 'Бествэй' => ['Лебедева Г.Г.', 'Карев Н.А. (пн, чт)', 'Гринько М.Д.'], 'Бритиш' => ['Чумакова В.В.', 'Зоценко С.З.'], 'Каусер' => ['Заморева М.М.'], 'Эль Ахья' => ['Мазитова Р.Р.', 'Реда', 'Щербакова О.В. (чт)']];
        foreach ($routes as $route => $names) {
            $path = $dir.'/Трансфер_'.$route.'.xlsx';
            $this->writeWorkbook($path, [['ФИО', 'Класс', 'Остановка', 'Телефон', 'Примечание']], collect($names)->map(fn ($name) => [$name, 'сотр', 'Точка', '', ''])->all(), 1);
            $this->transportPaths[] = $path;
        }
    }

    private function writeWorkbook(string $path, array $headers, array $rows, int $headerRow): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray($headers[0], null, 'A'.$headerRow);
        $sheet->fromArray($rows, null, 'A'.($headerRow + 1));
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }
}
