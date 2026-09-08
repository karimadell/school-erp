<?php

namespace Tests\Feature\Transport;

use App\Exceptions\TransportCapacityExceeded;
use App\Models\AcademicYear;
use App\Models\Bus;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentBootstrapImport;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\User;
use App\Services\Transport\RealStudentTransportAssignmentBootstrapService;
use App\Services\Transport\TransportAssignmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class RealStudentTransportAssignmentBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private array $paths;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole('admin');
        AcademicYear::forceCreate(['id' => 1, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        EnrollmentMode::forceCreate(['id' => 1, 'code' => 'full_time', 'name_ru' => 'Очная', 'is_active' => true, 'display_order' => 0]);
        Stage::forceCreate(['id' => 1, 'name' => 'Школа', 'order' => 1, 'is_active' => true]);
        Grade::forceCreate(['id' => 1, 'stage_id' => 1, 'name' => '1 класс', 'level' => 1]);
        SchoolClass::forceCreate(['id' => 1, 'grade_id' => 1, 'code' => '1-А', 'name_ar' => '1', 'name_ru' => '1-А', 'capacity' => 100, 'is_active' => true]);
        $this->catalog();
        $this->paths = $this->sourcesAndMetadata();
    }

    public function test_preview_resolves_all_64_source_rows_without_writes(): void
    {
        $before = $this->counts();
        $result = app(RealStudentTransportAssignmentBootstrapService::class)->preview($this->paths);

        $this->assertSame(64, $result['expected_assignments']);
        $this->assertSame(64, $result['new_assignments']);
        $this->assertSame(['Арабия' => 13, 'Бествэй' => 12, 'Бритиш' => 13, 'Каусер' => 14, 'Эль Ахья' => 12], $result['by_route']);
        $this->assertCount(64, collect($result['rows'])->pluck('source_key')->unique());
        $this->assertCount(64, collect($result['rows'])->pluck('enrollment_id')->unique());
        $this->assertSame($before, $this->counts());
    }

    public function test_apply_preserves_pickups_duplicate_name_identity_has_no_side_effects_and_is_idempotent(): void
    {
        $service = app(RealStudentTransportAssignmentBootstrapService::class);
        $before = $this->counts();
        $first = $service->apply($this->actor, $this->paths);
        $second = $service->apply($this->actor, $this->paths);

        $this->assertSame(64, $first['created_assignments']);
        $this->assertSame(0, $second['created_assignments']);
        $this->assertSame(['Арабия' => 13, 'Бествэй' => 12, 'Бритиш' => 13, 'Каусер' => 14, 'Эль Ахья' => 12], StudentTransportAssignment::query()->join('transport_routes', 'transport_routes.id', '=', 'student_transport_assignments.transport_route_id')->select('transport_routes.name', DB::raw('COUNT(*) n'))->groupBy('transport_routes.name')->pluck('n', 'name')->map(fn ($count) => (int) $count)->all());
        $this->assertSame('  Pickup Арабия 1  ', StudentTransportAssignment::where('enrollment_id', StudentBootstrapImport::where('route', 'Арабия')->orderBy('source_row')->value('enrollment_id'))->value('pickup_point'));

        $duplicates = StudentBootstrapImport::where('raw_full_name', 'Денисенко Александра')->orderBy('source_row')->get();
        $this->assertCount(2, $duplicates);
        $this->assertNotSame($duplicates[0]->enrollment_id, $duplicates[1]->enrollment_id);
        $this->assertSame([3, 5], StudentTransportAssignment::whereIn('enrollment_id', $duplicates->pluck('enrollment_id'))->orderBy('transport_route_id')->pluck('transport_route_id')->all());

        $after = $this->counts();
        $this->assertSame($before['invoices'], $after['invoices']);
        $this->assertSame($before['invoice_items'], $after['invoice_items']);
        $this->assertSame($before['invoice_payments'], $after['invoice_payments']);
        $this->assertSame($before['fee_prices'], $after['fee_prices']);
        $this->assertSame($before['subscriptions'], $after['subscriptions']);
        $this->assertSame($before['staff_assignments'], $after['staff_assignments']);
    }

    public function test_kauser_is_full_and_fifteenth_student_is_rejected_by_assignment_service(): void
    {
        app(RealStudentTransportAssignmentBootstrapService::class)->apply($this->actor, $this->paths);
        $this->assertSame(14, StudentTransportAssignment::where('bus_id', 4)->count());

        $student = Student::create(['name' => 'Пятнадцатый', 'status' => Student::STATUS_ACTIVE]);
        $enrollment = Enrollment::create($this->enrollmentAttributes($student->id));
        $this->expectException(TransportCapacityExceeded::class);
        app(TransportAssignmentService::class)->assign($enrollment, TransportRoute::findOrFail(4), Bus::findOrFail(4), ['effective_from' => '2026-09-01'], $this->actor);
    }

    public function test_apply_rolls_back_every_assignment_and_audit_on_failure(): void
    {
        $before = $this->counts();
        $created = 0;
        Event::listen('eloquent.created: '.StudentTransportAssignment::class, function () use (&$created): void {
            if (++$created === 2) {
                throw new \RuntimeException('forced assignment bootstrap failure');
            }
        });

        try {
            app(RealStudentTransportAssignmentBootstrapService::class)->apply($this->actor, $this->paths);
            $this->fail('Expected forced failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced assignment bootstrap failure', $e->getMessage());
        } finally {
            Event::forget('eloquent.created: '.StudentTransportAssignment::class);
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_missing_source_metadata_fails_closed(): void
    {
        StudentBootstrapImport::query()->firstOrFail()->delete();
        $this->expectException(ValidationException::class);
        app(RealStudentTransportAssignmentBootstrapService::class)->preview($this->paths);
    }

    public function test_conflicting_overlap_fails_closed_without_writes(): void
    {
        $import = StudentBootstrapImport::query()->firstOrFail();
        app(TransportAssignmentService::class)->assign(
            $import->enrollment,
            TransportRoute::findOrFail(2),
            Bus::findOrFail(2),
            ['effective_from' => '2026-09-01', 'pickup_point' => 'Conflicting pickup'],
            $this->actor,
        );
        $before = $this->counts();

        try {
            app(RealStudentTransportAssignmentBootstrapService::class)->apply($this->actor, $this->paths);
            $this->fail('Expected overlap conflict.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assignment', $e->errors());
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_pricing_zone_drift_including_an_unresolved_route_fails_closed(): void
    {
        TransportRoute::findOrFail(2)->update(['pricing_zone' => 'Invented Zone']);
        $before = $this->counts();

        try {
            app(RealStudentTransportAssignmentBootstrapService::class)->apply($this->actor, $this->paths);
            $this->fail('Expected pricing-zone mapping conflict.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('mapping', $e->errors());
        }

        $this->assertSame($before, $this->counts());
    }

    private function catalog(): void
    {
        $routes = [1 => ['Арабия', 'Zone 2'], 2 => ['Бествэй', null], 3 => ['Бритиш', null], 4 => ['Каусер', 'Zone 1'], 5 => ['Эль Ахья', 'Zone 3']];
        foreach ($routes as $id => [$name, $zone]) {
            TransportRoute::forceCreate(['id' => $id, 'name' => $name, 'pricing_zone' => $zone, 'capacity' => 14, 'is_active' => true]);
            Bus::forceCreate(['id' => $id, 'vehicle_code' => (string) $id, 'name' => "Микроавтобус {$id}", 'capacity' => 15, 'student_capacity' => 14, 'passenger_capacity' => 15, 'transport_route_id' => $id, 'is_active' => true]);
        }
    }

    private function sourcesAndMetadata(): array
    {
        $directory = sys_get_temp_dir().'/transport-assignments-'.bin2hex(random_bytes(5));
        mkdir($directory);
        $spec = ['Арабия' => 13, 'Бествэй' => 12, 'Бритиш' => 13, 'Каусер' => 14, 'Эль Ахья' => 12];
        $paths = [];
        $serial = 0;

        foreach ($spec as $route => $count) {
            $rows = [['№', 'ФИО', 'Класс', 'Остановка', 'Телефон', 'Примечание']];
            foreach (range(1, $count) as $number) {
                $sourceRow = $number + 1;
                $name = ($route === 'Бритиш' && $sourceRow === 14) || ($route === 'Эль Ахья' && $sourceRow === 5)
                    ? 'Денисенко Александра' : 'Ученик '.++$serial;
                $pickup = "  Pickup {$route} {$number}  ";
                $rows[] = [$number, $name, '1', $pickup, '', ''];
            }
            foreach (range(1, $route === 'Каусер' ? 1 : (in_array($route, ['Арабия', 'Бритиш'], true) ? 2 : 3)) as $number) {
                $rows[] = [$count + $number, "Сотрудник {$route} {$number}", 'сотр', 'Staff stop', '', ''];
            }

            $path = $directory.'/Трансфер_'.$route.'.xlsx';
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->fromArray($rows);
            (new Xlsx($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();
            $paths[] = $path;

            foreach (array_slice($rows, 1, $count) as $offset => $row) {
                $sourceRow = $offset + 2;
                $student = Student::create(['name' => $row[1], 'status' => Student::STATUS_ACTIVE]);
                $enrollment = Enrollment::create($this->enrollmentAttributes($student->id));
                $source = [basename($path), 'Worksheet', $sourceRow, $row[1]];
                StudentBootstrapImport::create([
                    'source_key' => hash('sha256', json_encode($source, JSON_UNESCAPED_UNICODE)),
                    'source_file' => $source[0], 'source_sheet' => $source[1], 'source_row' => $sourceRow,
                    'raw_full_name' => $row[1], 'raw_class' => '1', 'raw_pickup_point' => $row[3],
                    'raw_contact' => null, 'route' => $route, 'student_id' => $student->id, 'enrollment_id' => $enrollment->id,
                ]);
            }
        }

        return $paths;
    }

    private function enrollmentAttributes(int $studentId): array
    {
        return [
            'student_id' => $studentId, 'academic_year_id' => 1, 'enrollment_mode_id' => 1,
            'stage_id' => 1, 'grade_id' => 1, 'class_id' => 1, 'academic_year' => '2026/2027',
            'enrollment_date' => '2026-09-01', 'enrolled_at' => '2026-09-01',
            'status' => 'active', 'is_active' => true,
        ];
    }

    private function counts(): array
    {
        return [
            'assignments' => DB::table('student_transport_assignments')->count(),
            'audits' => DB::table('audit_logs')->count(),
            'staff_assignments' => DB::table('vehicle_staff_assignments')->count(),
            'subscriptions' => DB::table('student_service_subscriptions')->count(),
            'fee_prices' => DB::table('fee_prices')->count(),
            'invoices' => DB::table('invoices')->count(),
            'invoice_items' => DB::table('invoice_items')->count(),
            'invoice_payments' => DB::table('invoice_payments')->count(),
        ];
    }
}
