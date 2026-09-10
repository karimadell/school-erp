<?php

namespace Tests\Feature\MasterData;

use App\Events\MasterDataReconciliationPhase;
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
use App\Models\StudentListenerPlacement;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\User;
use App\Models\VehicleStaffAssignment;
use App\Services\MasterData\MasterDataReconciliationApplyService;
use App\Services\MasterData\MasterDataReconciliationPlanner;
use App\Services\MasterData\MasterDataReconciliationPreviewService;
use App\Services\MasterData\MasterDataWorkbookParser;
use App\Services\MasterData\MasterStaffImportService;
use App\Services\MasterData\MasterStudentImportService;
use App\Services\MasterData\WorkbookLoader;
use App\Services\Transport\RealStaffTransportAssignmentBootstrapService;
use App\Services\Transport\RealStudentTransportAssignmentBootstrapService;
use App\Support\RouteNameNormalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Normalizer;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
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
        foreach ([1 => ['Арабия', 'Zone 2'], 2 => ['Бествэй', null], 3 => ['Бритиш', null], 4 => ['Каусер', 'Zone 1'], 5 => ['Эль Ахья', 'Zone 3']] as $id => [$name, $zone]) {
            TransportRoute::forceCreate(['id' => $id, 'name' => $name, 'pricing_zone' => $zone, 'is_active' => true]);
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
        $this->assertSame(63, $students['safe_existing_matches']);
        $this->assertSame([], $students['review_required']);
        $this->assertSame(67, $students['new_formal_enrollments']);
        $this->assertSame(4, $students['listeners']);
        $this->assertSame(130, $students['expected_formal_ay1_enrollments']);
        $this->assertSame(63, $students['expected_current_transport_assignments']);
        $this->assertSame('ELSHEIKH_RESOLUTION', $students['elsheikh_resolution']['action']);
        $this->assertSame('DENISENKO_RESOLUTION', $students['denisenko_resolution']['action']);
        $this->assertEqualsCanonicalizing(['БЗ' => 4, 'ДО' => 12], $students['attendance_markers']);
        $this->assertSame('BLINOV_CORRECTION', $students['blinov_correction']['action']);
        $this->assertSame(26, $staff['source_rows']);
        $this->assertSame(26, $staff['new_staff_members']);
        $this->assertSame(11, $staff['transport_links_confirmed']);
        $this->assertSame(0, $staff['transport_links_review_required']);
        $this->assertSame(11, $staff['vehicle_staff_assignments_proposed']);
        $this->assertSame([1, 4], collect($staff['transport_links'])->firstWhere('display_name', 'Карев Н.А.')['weekdays']);
        $this->assertSame([4], collect($staff['transport_links'])->firstWhere('display_name', 'Щербакова О.В.')['weekdays']);
        $this->assertSame('Лебедева Галина Геннадьевна', collect($staff['transport_links'])->firstWhere('display_name', 'Лебедева Г.Г.')['master_name']);
        $this->assertSame('Чумакова Виктория Владимировна', collect($staff['transport_links'])->firstWhere('display_name', 'Чумакова В.В.')['master_name']);
        $this->assertSame('Щербакова Ольга Викторовна', collect($staff['transport_links'])->firstWhere('display_name', 'Щербакова О.В.')['master_name']);
        $this->assertSame(3, collect($staff['transport_links'])->where('phone_evidence', 'CONFIRMED_IDENTITY_STALE_PHONE_PRESERVED')->count());
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
        $this->assertSame(67, $first['created_enrollments']);
        $this->assertSame(4, $first['created_listeners']);
        $this->assertSame(1, $first['merged_students']);
        $this->assertSame(1, $first['ended_transport_assignments']);
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
        $this->assertSame(0, Enrollment::where('study_attendance_mode', 'БЗ')->count());
        $this->assertSame(4, StudentListenerPlacement::where('source_marker', 'БЗ')->count());
        $this->assertSame(130, Enrollment::where('academic_year_id', 1)->where('is_active', true)->count());
        $this->assertSame(8, Enrollment::findOrFail(StudentBootstrapImport::where('raw_full_name', 'Блинов Добрыня')->value('enrollment_id'))->grade->level);
        $this->assertSame(64, StudentTransportAssignment::count());
        $this->assertSame(63, StudentTransportAssignment::where('status', 'active')->whereNull('effective_to')->count());
        $this->assertNotSame($transportHash, hash('sha256', StudentTransportAssignment::orderBy('id')->get()->toJson()));
        $this->assertSame('Эльшейх Сухайб', StudentBootstrapImport::where('raw_full_name', 'Эльшейх Адам')->firstOrFail()->student->fresh()->name);
        $this->assertSame('Адам', StudentBootstrapImport::where('raw_full_name', 'Эльшейх Адам')->firstOrFail()->student->fresh()->preferred_name);
        $denis = StudentBootstrapImport::where('raw_full_name', 'Денисенко Александра')->get();
        $this->assertSame(1, $denis->filter(fn ($i) => $i->student->merged_into_student_id)->count());
        $this->assertSame(1, $denis->filter(fn ($i) => ! $i->student->merged_into_student_id)->count());
        $this->assertSame(1, StudentTransportAssignment::where('enrollment_id', $denis->first(fn ($i) => str_contains($i->source_file, 'Эль Ахья'))->enrollment_id)->where('bus_id', 5)->where('status', 'active')->count());
        $this->assertSame(1, StudentTransportAssignment::where('enrollment_id', $denis->first(fn ($i) => str_contains($i->source_file, 'Бритиш'))->enrollment_id)->where('bus_id', 3)->where('status', 'ended')->whereNotNull('effective_to')->count());
        $this->assertSame(1, AuditLog::where('action', 'master_duplicate_enrollment_merged')->where('user_id', $this->actor->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'master_student_identity_merged')->where('user_id', $this->actor->id)->count());
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

    public function test_missing_bootstrap_metadata_does_not_block_preview_or_write(): void
    {
        StudentTransportAssignment::query()->delete();
        StudentBootstrapImport::query()->delete();
        \App\Models\StaffTransportBootstrapImport::query()->delete();
        Enrollment::query()->delete();
        Student::query()->delete();
        Bus::query()->delete();
        TransportRoute::query()->delete();
        $before = $this->counts();
        $preview = app(MasterStudentImportService::class)->preview($this->studentPath);
        $studentTransport = app(RealStudentTransportAssignmentBootstrapService::class)->preview($this->transportPaths, $this->studentPath);
        $studentTransportAgain = app(RealStudentTransportAssignmentBootstrapService::class)->preview($this->transportPaths, $this->studentPath);
        $warnings = [];
        set_error_handler(function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];

            return true;
        }, E_WARNING);
        try {
            $staffTransport = app(RealStaffTransportAssignmentBootstrapService::class)->preview($this->transportPaths, $this->staffPath);
            $complete = app(MasterDataReconciliationPreviewService::class)->preview($this->studentPath, $this->staffPath, $this->transportPaths);
            $completeAgain = app(MasterDataReconciliationPreviewService::class)->preview($this->studentPath, $this->staffPath, $this->transportPaths);
        } finally {
            restore_error_handler();
        }
        $this->assertSame(134, $preview['source_rows']);
        $this->assertSame([], $preview['review_required']);
        $this->assertSame(63, $studentTransport['expected_assignments']);
        $this->assertTrue(collect($studentTransport['rows'])->every(fn ($row) => $row['student_id'] === null && $row['enrollment_id'] === null));
        $this->assertTrue(collect($studentTransport['rows'])->every(fn ($row) => $row['catalog_status'] === 'PLANNED'));
        $this->assertCount(1, $studentTransport['historical_evidence']);
        $this->assertSame('Эль Ахья', collect($studentTransport['rows'])->firstWhere('canonical_name', 'Денисенко Александра')['route']);
        $this->assertSame(11, $staffTransport['expected_staff']);
        $this->assertSame(['PLANNED_MASTER' => 11], $staffTransport['identity_summary']);
        $this->assertSame(['Арабия' => 2, 'Бествэй' => 3, 'Бритиш' => 2, 'Каусер' => 1, 'Эль Ахья' => 3], $staffTransport['by_route']);
        $this->assertSame(['Арабия' => 13, 'Бествэй' => 12, 'Бритиш' => 12, 'Каусер' => 14, 'Эль Ахья' => 12], $studentTransport['by_route']);
        $this->assertSame(RouteNameNormalizer::key('Бествэй'), RouteNameNormalizer::key(Normalizer::normalize('Бествэй', Normalizer::FORM_D)));
        $this->assertSame([
            'Арабия' => [13, 2, 15], 'Бествэй' => [12, 3, 15], 'Бритиш' => [12, 2, 14],
            'Каусер' => [14, 1, 15], 'Эль Ахья' => [12, 3, 15],
        ], collect($staffTransport['capacity'])->mapWithKeys(fn ($row) => [$row['route'] => [$row['students'], $row['peak_staff'], $row['peak_physical_occupancy']]])->all());
        $this->assertSame($studentTransport, $studentTransportAgain);
        $this->assertSame($complete, $completeAgain);
        $this->assertSame([], $warnings);
        $this->assertSame(63, $complete['student_transport']['expected_assignments']);
        $this->assertSame(11, $complete['staff_transport']['expected_staff']);
        $this->assertTrue($complete['capacity_valid']);
        $this->assertSame(0, collect($complete['student_transport']['rows'])->where('canonical_name', 'Денисенко Александра')->where('route', 'Бритиш')->count());
        $this->assertSame(1, collect($complete['student_transport']['historical_evidence'])->where('canonical_name', 'Денисенко Александра')->where('route', 'Бритиш')->count());
        $this->assertSame(['master_students', 'master_staff', 'transport_catalog', 'student_transport_assignments', 'vehicle_staff_assignments'], $complete['apply_order']);
        $this->assertSame($before, $this->counts());
    }

    public function test_new_elsheikh_identity_persists_preferred_name_idempotently_and_unrelated_finance_is_untouched(): void
    {
        StudentTransportAssignment::query()->delete();
        StudentBootstrapImport::query()->delete();
        Enrollment::query()->delete();
        Student::query()->delete();
        $existing = Student::create(['name' => 'UAT unrelated finance child', 'status' => Student::STATUS_ACTIVE]);
        $enrollment = Enrollment::create(['student_id' => $existing->id, 'academic_year_id' => 1, 'enrollment_mode_id' => 1, 'stage_id' => 1, 'grade_id' => 1, 'class_id' => 1,
            'academic_year' => '2026/2027', 'enrollment_date' => '2026-09-01', 'enrolled_at' => '2026-09-01', 'status' => 'active', 'is_active' => true]);
        $invoiceId = DB::table('invoices')->insertGetId(['student_id' => $existing->id, 'academic_year_id' => 1, 'customer_name' => $existing->name, 'total_amount' => 100, 'subtotal_amount' => 100,
            'paid_amount' => 0, 'remaining_amount' => 100, 'status' => 'unpaid', 'currency' => 'EGP', 'created_at' => now(), 'updated_at' => now()]);
        $before = [$existing->fresh()->toArray(), $enrollment->fresh()->toArray(), DB::table('invoices')->find($invoiceId)->student_id];

        $preview = app(MasterStudentImportService::class)->preview($this->studentPath);
        $elsheikh = collect($preview['rows'])->firstWhere('raw_name', 'Эльшейх Сухайб');
        $this->assertSame('Эльшейх Сухайб', $elsheikh['canonical_name']);
        $this->assertSame('Адам', $elsheikh['preferred_name']);
        $this->assertSame(['Адам', 'Эльшейх Адам'], $elsheikh['source_aliases']);
        app(MasterStudentImportService::class)->apply($this->actor, $this->studentPath);
        app(MasterStudentImportService::class)->apply($this->actor, $this->studentPath);

        $canonical = Student::where('name', 'Эльшейх Сухайб')->sole();
        $this->assertSame('Адам', $canonical->preferred_name);
        $this->assertSame(1, Student::where(fn ($q) => $q->where('name', 'Эльшейх Сухайб')->orWhere('name', 'Эльшейх Адам'))->count());
        $this->assertSame($before[0], $existing->fresh()->toArray());
        $this->assertSame($before[1], $enrollment->fresh()->toArray());
        $this->assertSame($before[2], DB::table('invoices')->find($invoiceId)->student_id);
    }

    public function test_authoritative_transport_apply_resolves_master_planning_keys_and_is_idempotent(): void
    {
        StudentTransportAssignment::query()->delete();
        StudentBootstrapImport::query()->delete();
        Enrollment::query()->delete();
        Student::query()->delete();
        StaffMember::query()->delete();

        app(MasterStudentImportService::class)->apply($this->actor, $this->studentPath);
        app(MasterStaffImportService::class)->apply($this->actor, $this->staffPath, $this->transportPaths);
        $students = app(RealStudentTransportAssignmentBootstrapService::class);
        $staff = app(RealStaffTransportAssignmentBootstrapService::class);
        $studentFirst = $students->apply($this->actor, $this->transportPaths, $this->studentPath);
        $staffFirst = $staff->apply($this->actor, $this->transportPaths, $this->staffPath);
        $studentSecond = $students->apply($this->actor, $this->transportPaths, $this->studentPath);
        $staffSecond = $staff->apply($this->actor, $this->transportPaths, $this->staffPath);

        $this->assertSame(63, $studentFirst['created_assignments']);
        $this->assertSame(11, $staffFirst['created_assignments']);
        $this->assertSame(0, $studentSecond['created_assignments']);
        $this->assertSame(0, $staffSecond['created_assignments']);
        $this->assertSame(63, StudentTransportAssignment::where('status', 'active')->whereNull('effective_to')->count());
        $this->assertSame(11, VehicleStaffAssignment::count());
        $denis = MasterStudentImport::where('raw_name', 'Денисенко Александра')->sole();
        $this->assertSame(['Эль Ахья'], StudentTransportAssignment::where('enrollment_id', $denis->enrollment_id)->where('status', 'active')
            ->join('transport_routes', 'transport_routes.id', '=', 'student_transport_assignments.transport_route_id')->pluck('transport_routes.name')->all());
        $this->assertSame(0, StudentTransportAssignment::where('enrollment_id', $denis->enrollment_id)->where('transport_route_id', 3)->where('status', 'active')->count());
        $this->assertSame(0, \App\Models\StaffTransportBootstrapImport::count());
        $this->assertSame(0, StudentBootstrapImport::count());
    }

    public function test_eighteen_existing_students_without_bootstrap_are_reconciled_deterministically(): void
    {
        StudentTransportAssignment::query()->delete();
        StudentBootstrapImport::query()->delete();
        Enrollment::query()->delete();
        Student::query()->delete();
        $rows = collect(app(MasterDataWorkbookParser::class)->students($this->studentPath))->take(18);
        foreach ($rows as $row) {
            Student::create(['name' => $row['raw_name'], 'status' => Student::STATUS_ACTIVE]);
        }
        $before = $this->counts();
        $first = app(MasterStudentImportService::class)->preview($this->studentPath);
        $second = app(MasterStudentImportService::class)->preview($this->studentPath);
        $this->assertSame(18, $first['safe_existing_matches']);
        $this->assertSame(116, $first['new_students']);
        $this->assertSame($first, $second);
        $this->assertSame($before, $this->counts());
    }

    public function test_finance_linked_exact_student_is_reused_and_ambiguous_finance_identity_is_review_required(): void
    {
        $row = app(MasterDataWorkbookParser::class)->students($this->studentPath)[0];
        $student = Student::where('name', $row['raw_name'])->firstOrFail();
        DB::table('invoices')->insert(['student_id' => $student->id, 'academic_year_id' => 1, 'customer_name' => $student->name, 'total_amount' => 100, 'subtotal_amount' => 100,
            'paid_amount' => 0, 'remaining_amount' => 100, 'status' => 'unpaid', 'currency' => 'EGP', 'created_at' => now(), 'updated_at' => now()]);
        $preview = app(MasterStudentImportService::class)->preview($this->studentPath);
        $item = collect($preview['rows'])->firstWhere('raw_name', $row['raw_name']);
        $this->assertSame($student->id, $item['student_id']);
        $this->assertTrue($item['finance_linked']);
        $this->assertSame(1, $item['finance_evidence'][$student->id]['invoices']);

        Student::create(['name' => $row['raw_name'], 'status' => Student::STATUS_ACTIVE]);
        $before = $this->counts();
        $ambiguous = app(MasterStudentImportService::class)->preview($this->studentPath);
        $review = collect($ambiguous['review_required'])->firstWhere('raw_name', $row['raw_name']);
        $this->assertSame('REVIEW_REQUIRED', $review['action']);
        $this->assertTrue($review['finance_linked']);
        $this->assertSame($before, $this->counts());
    }

    public function test_staff_identity_and_catalog_preview_succeeds_without_buses_and_reuses_routes(): void
    {
        StudentTransportAssignment::query()->delete();
        Bus::query()->delete();
        TransportRoute::whereNotIn('name', ['Арабия', 'Бритиш', 'Каусер'])->delete();
        $before = $this->counts();
        $first = app(MasterStaffImportService::class)->preview($this->staffPath, $this->transportPaths);
        $second = app(MasterStaffImportService::class)->preview($this->staffPath, $this->transportPaths);
        $catalog = collect($first['transport_catalog']);
        $this->assertSame(26, $first['new_staff_members']);
        $this->assertSame(11, $first['transport_links_confirmed']);
        $this->assertSame(0, $first['vehicle_staff_assignments_ready']);
        $this->assertSame(5, $catalog->count());
        $this->assertSame(3, $catalog->where('route_action', 'REUSE_ROUTE')->count());
        $this->assertSame(2, $catalog->where('route_action', 'CREATE_ROUTE')->count());
        $this->assertSame(5, $catalog->where('bus_action', 'CREATE_BUS')->count());
        $this->assertSame($first, $second);
        $this->assertSame($before, $this->counts());
    }

    public function test_transport_catalog_preview_enforces_student_and_passenger_capacity(): void
    {
        $path = sys_get_temp_dir().'/Трансфер_Арабия_'.bin2hex(random_bytes(3)).'.xlsx';
        $rows = collect(range(1, 15))->map(fn ($i) => ["Ребенок {$i}", '1', 'Точка', '', ''])->all();
        $rows[] = ['Егорова О.В.', 'сотр', 'Точка', '', ''];
        $this->writeWorkbook($path, [['ФИО', 'Класс', 'Остановка', 'Телефон', 'Примечание']], $rows, 1);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->expectExceptionMessage('exceeds 14 students / 15 passengers');
        app(MasterStaffImportService::class)->preview($this->staffPath, [$path]);
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

    public function test_fast_plan_is_zero_write_deterministic_and_loads_each_workbook_once(): void
    {
        $this->resetToUatBaseline();
        $before = $this->counts();
        $planner = app(MasterDataReconciliationPlanner::class);
        $first = $planner->plan($this->studentPath, $this->staffPath, $this->transportPaths);
        $second = $planner->plan($this->studentPath, $this->staffPath, $this->transportPaths);

        $this->assertSame($before, $this->counts());
        $this->assertSame($first->planHash, $second->planHash);
        $this->assertSame(7, app(WorkbookLoader::class)->physicalLoadCount());
        $this->assertSame(134, $first->summary()['master_students']);
        $this->assertSame(63, $first->summary()['new_student_assignments']);
        $this->assertSame(11, $first->summary()['new_staff_assignments']);
    }

    public function test_fast_apply_rejects_a_stale_baseline_before_first_business_write(): void
    {
        $this->resetToUatBaseline();
        $plan = app(MasterDataReconciliationPlanner::class)->plan($this->studentPath, $this->staffPath, $this->transportPaths);
        Student::create(['name' => 'Concurrent change', 'status' => Student::STATUS_ACTIVE]);

        try {
            app(MasterDataReconciliationApplyService::class)->apply($this->actor, $plan);
            $this->fail('Expected stale-plan abort.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('stale', $exception->getMessage());
        }
        $this->assertSame(0, MasterStudentImport::count());
        $this->assertSame(0, StaffMasterImport::count());
    }

    public function test_fast_apply_rejects_finance_and_protected_student_drift_before_writing(): void
    {
        foreach (['finance', 'protected-student'] as $drift) {
            $this->resetToUatBaseline();
            $protected = Student::query()->firstOrFail();
            $invoiceId = DB::table('invoices')->insertGetId([
                'student_id' => $protected->id, 'academic_year_id' => 1, 'customer_name' => $protected->name,
                'total_amount' => 100, 'subtotal_amount' => 100, 'paid_amount' => 0, 'remaining_amount' => 100,
                'status' => 'unpaid', 'currency' => 'EGP', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $plan = app(MasterDataReconciliationPlanner::class)->plan($this->studentPath, $this->staffPath, $this->transportPaths);
            if ($drift === 'finance') {
                DB::table('invoices')->where('id', $invoiceId)->update(['customer_name' => 'Concurrent finance drift']);
            } else {
                Student::withoutEvents(fn () => Student::whereKey($protected->id)->update(['name' => 'Concurrent protected drift']));
            }

            try {
                app(MasterDataReconciliationApplyService::class)->apply($this->actor, $plan);
                $this->fail("Expected {$drift} stale-plan abort.");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('stale', $exception->getMessage());
            }
            $this->assertSame(0, MasterStudentImport::count());
            $this->assertSame(0, StaffMasterImport::count());
        }
    }

    public function test_workbook_loader_refuses_a_physical_load_after_persistence_starts(): void
    {
        $loader = new WorkbookLoader;
        $loader->forbidFurtherPhysicalLoads();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden inside');
        $loader->load($this->studentPath);
    }

    #[DataProvider('reconciliationFailurePhases')]
    public function test_fast_apply_rolls_back_every_phase(string $phase): void
    {
        $this->resetToUatBaseline();
        $plan = app(MasterDataReconciliationPlanner::class)->plan($this->studentPath, $this->staffPath, $this->transportPaths);
        $before = $this->counts();
        Event::listen(MasterDataReconciliationPhase::class, function (MasterDataReconciliationPhase $event) use ($phase): void {
            if ($event->phase === $phase) {
                throw new \RuntimeException("forced {$phase}");
            }
        });
        try {
            app(MasterDataReconciliationApplyService::class)->apply($this->actor, $plan);
            $this->fail('Expected injected failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame("forced {$phase}", $exception->getMessage());
        } finally {
            Event::forget(MasterDataReconciliationPhase::class);
        }
        $this->assertSame($before, $this->counts());
    }

    public static function reconciliationFailurePhases(): array
    {
        return [
            'student persistence' => ['students_persisted'],
            'staff persistence' => ['staff_persisted'],
            'transport persistence' => ['transport_persisted'],
            'final assertion' => ['final_assertions_passed'],
        ];
    }

    public function test_fast_apply_succeeds_atomically_and_the_next_plan_is_idempotent(): void
    {
        $this->resetToUatBaseline();
        $planner = app(MasterDataReconciliationPlanner::class);
        $plan = $planner->plan($this->studentPath, $this->staffPath, $this->transportPaths);
        $result = app(MasterDataReconciliationApplyService::class)->apply($this->actor, $plan);

        $this->assertSame(152, Student::count());
        $this->assertSame(147, Enrollment::count());
        $this->assertSame(4, StudentListenerPlacement::count());
        $this->assertSame(26, StaffMember::count());
        $this->assertSame(8, TransportRoute::count());
        $this->assertSame(5, Bus::count());
        $this->assertSame(63, StudentTransportAssignment::count());
        $this->assertSame(11, VehicleStaffAssignment::count());
        $this->assertLessThan(60, $result['transaction_seconds']);

        // A command/app lifecycle owns one immutable loader. A new scope models
        // the required post-apply preview invocation.
        app()->forgetScopedInstances();
        $next = app(MasterDataReconciliationPlanner::class)->plan($this->studentPath, $this->staffPath, $this->transportPaths)->summary();
        $this->assertSame(0, $next['new_students']);
        $this->assertSame(0, $next['new_staff']);
        $this->assertSame(0, $next['new_routes']);
        $this->assertSame(0, $next['new_buses']);
        $this->assertSame(0, $next['new_student_assignments']);
        $this->assertSame(0, $next['new_staff_assignments']);
    }

    private function resetToUatBaseline(): void
    {
        DB::table('invoice_payments')->delete();
        DB::table('invoice_items')->delete();
        DB::table('invoices')->delete();
        DB::table('student_service_subscriptions')->delete();
        VehicleStaffAssignment::query()->delete();
        StudentTransportAssignment::query()->delete();
        StaffMasterImport::query()->delete();
        MasterStudentImport::query()->delete();
        StudentBootstrapImport::query()->delete();
        \App\Models\StaffTransportBootstrapImport::query()->delete();
        StudentListenerPlacement::query()->delete();
        Enrollment::query()->delete();
        StaffMember::query()->delete();
        Bus::query()->delete();
        TransportRoute::query()->delete();
        Student::query()->delete();
        AuditLog::query()->delete();

        foreach (range(1, 18) as $number) {
            $student = Student::create(['name' => "Preserved UAT {$number}", 'status' => Student::STATUS_ACTIVE]);
            if ($number <= 17) {
                Enrollment::create(['student_id' => $student->id, 'academic_year_id' => 1, 'enrollment_mode_id' => 1, 'stage_id' => 1, 'grade_id' => 1, 'class_id' => 1,
                    'academic_year' => '2026/2027', 'enrollment_date' => '2026-09-01', 'enrolled_at' => '2026-09-01', 'status' => 'active', 'is_active' => true]);
            }
        }
        foreach (range(1, 3) as $number) {
            TransportRoute::create(['name' => "Unrelated route {$number}", 'pricing_zone' => null, 'is_active' => true]);
        }
        AuditLog::query()->delete();
        app()->forgetScopedInstances();
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
            $routeId = str_contains($s['file'], 'Бритиш') ? 3 : (str_contains($s['file'], 'Каусер') ? 4 : (str_contains($s['file'], 'Эль Ахья') ? 5 : 1));
            StudentBootstrapImport::create(['source_key' => hash('sha256', $s['file'].'-'.$i), 'source_file' => $s['file'], 'source_sheet' => 'Sheet1', 'source_row' => $s['row'], 'raw_full_name' => $s['name'], 'raw_class' => (string) $s['class'], 'raw_contact' => $s['contact'], 'route' => TransportRoute::findOrFail($routeId)->name, 'student_id' => $student->id, 'enrollment_id' => $enrollment->id]);
            StudentTransportAssignment::create(['enrollment_id' => $enrollment->id, 'transport_route_id' => $routeId, 'bus_id' => $routeId, 'effective_from' => '2026-09-01', 'status' => 'active', 'created_by' => $this->actor->id]);
        }
        Student::create(['name' => 'Demo Student', 'status' => 'active']);
    }

    private function counts(): array
    {
        return ['students' => Student::count(), 'enrollments' => Enrollment::count(), 'listeners' => StudentListenerPlacement::count(), 'master_students' => MasterStudentImport::count(), 'staff' => StaffMember::count(), 'master_staff' => StaffMasterImport::count(), 'routes' => TransportRoute::count(), 'buses' => Bus::count(), 'student_transport' => StudentTransportAssignment::count(), 'staff_transport' => VehicleStaffAssignment::count()] + $this->finance();
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

        $full = ['Егорова Ольга Викторовна', 'Герасимович Наталья Ивановна', 'Лебедева Галина Геннадьевна', 'Карев Николай Александрович', 'Гринько Марина Дмитриевна', 'Чумакова Виктория Владимировна', 'Зоценко Светлана Зиновьевна', 'Заморева Марина Михайловна', 'Мазитова Римма Ринатовна', 'Реда', 'Щербакова Ольга Викторовна'];
        while (count($full) < 26) {
            $full[] = 'Сотрудник Имя '.count($full);
        }
        $staffRows = collect($full)->map(fn ($name, $i) => [$i + 1, $name, null, null, null, '01.01.1900', 'Сотрудник', null, null, '+200'.$i])->all();
        $this->writeWorkbook($this->staffPath, [['№', 'ФИО', '', '', '', 'Дата рождения', 'Должность', '', '', 'Телефон']], $staffRows, 4);

        $staffByRoute = ['Арабия' => ['Егорова О.В.', 'Герасимович Н.И.'], 'Бествэй' => ['Лебедева Г.Г.', 'Карев Н.А. (пн, чт)', 'Гринько М.Д.'], 'Бритиш' => ['Чумакова В.В.', 'Зоценко С.З.'], 'Каусер' => ['Заморева М.М.'], 'Эль Ахья' => ['Мазитова Р.Р.', 'Реда', 'Щербакова О.В. (чт)']];
        $ordinary = collect(range(1, 60))->map(fn ($n) => "Ученик {$n}");
        $studentsByRoute = [
            'Арабия' => $ordinary->slice(0, 13)->values()->all(),
            'Бествэй' => $ordinary->slice(13, 12)->values()->all(),
            'Бритиш' => [...$ordinary->slice(25, 12)->values()->all(), 'Денисенко Александра'],
            'Каусер' => [...$ordinary->slice(37, 13)->values()->all(), 'Эльшейх Адам'],
            'Эль Ахья' => [...$ordinary->slice(50, 10)->values()->all(), 'Блинов Добрыня', 'Денисенко Александра'],
        ];
        foreach ($staffByRoute as $route => $names) {
            $sourceRoute = Normalizer::normalize($route, Normalizer::FORM_D);
            $path = $dir.'/Трансфер_'.$sourceRoute.'.xlsx';
            $studentRowsForRoute = collect($studentsByRoute[$route])->map(fn ($name) => [$name, '1', 'Точка ученика', '', ''])->all();
            $staffRowsForRoute = collect($names)->map(fn ($name) => [$name, 'сотр', 'Точка', '', ''])->all();
            $this->writeWorkbook($path, [['ФИО', 'Класс', 'Остановка', 'Телефон', 'Примечание']], [...$studentRowsForRoute, ...$staffRowsForRoute], 1);
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
