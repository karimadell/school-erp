<?php

namespace Tests\Feature\Transport;

use App\Models\AcademicYear;
use App\Models\Bus;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\User;
use App\Models\VehicleStaffAssignment;
use App\Services\Transport\RealStaffTransportAssignmentBootstrapService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class RealStaffTransportAssignmentBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private array $paths;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->actor = User::factory()->create(['name' => 'Bootstrap Admin', 'is_active' => true]);
        $this->actor->assignRole('admin');
        $this->structure();
        $this->catalogAndStudentOccupancy();
        $this->paths = $this->sourcesAndUsers();
    }

    public function test_preview_extracts_only_11_staff_with_deterministic_identity_weekdays_and_safe_capacity(): void
    {
        $before = $this->counts();
        $result = app(RealStaffTransportAssignmentBootstrapService::class)->preview($this->paths);
        $rows = collect($result['rows']);

        $this->assertSame(11, $result['expected_staff']);
        $this->assertSame(['MATCHED' => 11], $result['identity_summary']);
        $this->assertCount(11, $rows);
        $this->assertNotContains('STUDENT', $rows->pluck('type'));
        $this->assertTrue($rows->every(fn ($row) => $row['proposed_role'] === VehicleStaffAssignment::ROLE_STAFF_PASSENGER));
        $this->assertSame([1, 4], $rows->firstWhere('raw_full_name', 'Карев Н.А. (пн, чт)')['weekdays']);
        $this->assertSame([4], $rows->firstWhere('raw_full_name', 'Щербакова О.В. (чт)')['weekdays']);
        $this->assertNull($rows->firstWhere('raw_full_name', 'Егорова О.В.')['weekdays']);
        $this->assertTrue(collect($result['capacity'])->every(fn ($item) => $item['peak_physical_occupancy'] === 15 && $item['passenger_capacity'] === 15));
        $this->assertSame($before, $this->counts());
    }

    public function test_apply_is_idempotent_preserves_source_marker_and_has_no_side_effects(): void
    {
        $service = app(RealStaffTransportAssignmentBootstrapService::class);
        $before = $this->counts();
        $studentHash = $this->studentAssignmentHash();
        $first = $service->apply($this->actor, $this->paths);
        $second = $service->apply($this->actor, $this->paths);

        $this->assertSame(11, $first['created_assignments']);
        $this->assertSame(0, $second['created_assignments']);
        $this->assertSame(11, VehicleStaffAssignment::count());
        $this->assertSame(11, VehicleStaffAssignment::where('role', 'staff_passenger')->count());
        $this->assertSame(11, VehicleStaffAssignment::where('change_reason', 'like', 'Controlled real staff transport bootstrap:%')->count());
        $this->assertStringContainsString('  Staff stop Арабия 0  ', VehicleStaffAssignment::where('bus_id', 1)->oldest('id')->value('change_reason'));
        $this->assertSame($studentHash, $this->studentAssignmentHash());
        $after = $this->counts();
        foreach (['students', 'enrollments', 'student_assignments', 'fee_prices', 'invoices', 'invoice_items', 'invoice_payments', 'subscriptions'] as $key) {
            $this->assertSame($before[$key], $after[$key]);
        }
    }

    public function test_missing_identity_and_multiple_exact_identities_are_classified_without_selection(): void
    {
        User::where('name', 'Егорова Ольга Викторовна')->delete();
        User::factory()->create(['name' => 'Егорова Ирина Петровна', 'is_active' => true]);
        $possible = app(RealStaffTransportAssignmentBootstrapService::class)->preview($this->paths);
        $egorova = collect($possible['rows'])->firstWhere('raw_full_name', 'Егорова О.В.');
        $this->assertSame('POSSIBLE_MATCH', $egorova['identity_status']);
        $this->assertNull($egorova['user_id']);

        User::factory()->create(['name' => 'Карев Николай Андреевич', 'is_active' => true]);
        $conflict = app(RealStaffTransportAssignmentBootstrapService::class)->preview($this->paths);
        $karev = collect($conflict['rows'])->firstWhere('raw_full_name', 'Карев Н.А. (пн, чт)');
        $this->assertSame('CONFLICT', $karev['identity_status']);
        $this->assertCount(2, $karev['candidate_user_ids']);
    }

    public function test_capacity_conflict_fails_closed(): void
    {
        Bus::findOrFail(1)->forceFill(['passenger_capacity' => 14])->saveQuietly();
        $this->expectException(ValidationException::class);
        app(RealStaffTransportAssignmentBootstrapService::class)->preview($this->paths);
    }

    public function test_apply_rolls_back_all_staff_assignments_and_audits_on_failure(): void
    {
        $before = $this->counts();
        $created = 0;
        Event::listen('eloquent.created: '.VehicleStaffAssignment::class, function () use (&$created): void {
            if (++$created === 2) {
                throw new \RuntimeException('forced staff bootstrap failure');
            }
        });
        try {
            app(RealStaffTransportAssignmentBootstrapService::class)->apply($this->actor, $this->paths);
            $this->fail('Expected forced failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced staff bootstrap failure', $e->getMessage());
        } finally {
            Event::forget('eloquent.created: '.VehicleStaffAssignment::class);
        }
        $this->assertSame($before, $this->counts());
    }

    private function structure(): void
    {
        AcademicYear::forceCreate(['id' => 1, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        EnrollmentMode::forceCreate(['id' => 1, 'code' => 'full_time', 'name_ru' => 'Очная', 'is_active' => true, 'display_order' => 0]);
        Stage::forceCreate(['id' => 1, 'name' => 'Школа', 'order' => 1, 'is_active' => true]);
        Grade::forceCreate(['id' => 1, 'stage_id' => 1, 'name' => '1 класс', 'level' => 1]);
        SchoolClass::forceCreate(['id' => 1, 'grade_id' => 1, 'code' => '1-А', 'name_ar' => '1', 'name_ru' => '1-А', 'capacity' => 100, 'is_active' => true]);
    }

    private function catalogAndStudentOccupancy(): void
    {
        $routes = [1 => ['Арабия', 13], 2 => ['Бествэй', 12], 3 => ['Бритиш', 13], 4 => ['Каусер', 14], 5 => ['Эль Ахья', 12]];
        foreach ($routes as $id => [$name, $students]) {
            TransportRoute::forceCreate(['id' => $id, 'name' => $name, 'capacity' => 14, 'is_active' => true]);
            Bus::forceCreate(['id' => $id, 'vehicle_code' => (string) $id, 'name' => "Bus {$id}", 'capacity' => 15, 'student_capacity' => 14, 'passenger_capacity' => 15, 'transport_route_id' => $id, 'is_active' => true]);
            foreach (range(1, $students) as $number) {
                $student = Student::create(['name' => "Student {$id}-{$number}", 'status' => Student::STATUS_ACTIVE]);
                $enrollment = Enrollment::create(['student_id' => $student->id, 'academic_year_id' => 1, 'enrollment_mode_id' => 1, 'stage_id' => 1, 'grade_id' => 1, 'class_id' => 1, 'academic_year' => '2026/2027', 'enrollment_date' => '2026-09-01', 'enrolled_at' => '2026-09-01', 'status' => 'active', 'is_active' => true]);
                StudentTransportAssignment::create(['enrollment_id' => $enrollment->id, 'transport_route_id' => $id, 'bus_id' => $id, 'effective_from' => '2026-09-01', 'status' => 'active', 'created_by' => $this->actor->id]);
            }
        }
    }

    private function sourcesAndUsers(): array
    {
        $staff = [
            'Арабия' => [['Егорова О.В.', 'Егорова Ольга Викторовна'], ['Герасимович Н.И.', 'Герасимович Наталья Ивановна']],
            'Бествэй' => [['Лебедева Г.Г.', 'Лебедева Галина Георгиевна'], ['Карев Н.А. (пн, чт)', 'Карев Николай Андреевич'], ['Гринько М.Д.', 'Гринько Мария Дмитриевна']],
            'Бритиш' => [['Чумакова В.В.', 'Чумакова Валентина Викторовна'], ['Зоценко С.З.', 'Зоценко Сергей Захарович']],
            'Каусер' => [['Заморева М.М.', 'Заморева Марина Михайловна']],
            'Эль Ахья' => [['Мазитова Р.Р.', 'Мазитова Римма Романовна'], ['Реда', 'Реда'], ['Щербакова О.В. (чт)', 'Щербакова Ольга Викторовна']],
        ];
        $studentCounts = ['Арабия' => 13, 'Бествэй' => 12, 'Бритиш' => 13, 'Каусер' => 14, 'Эль Ахья' => 12];
        $directory = sys_get_temp_dir().'/staff-bootstrap-'.bin2hex(random_bytes(5));
        mkdir($directory);
        $paths = [];
        foreach ($staff as $route => $people) {
            $rows = [['№', 'ФИО', 'Класс', 'Остановка', 'Телефон', 'Примечание']];
            foreach (range(1, $studentCounts[$route]) as $number) {
                $rows[] = [$number, "Source student {$route} {$number}", '1', "Student stop {$number}", '', ''];
            }
            foreach ($people as $offset => [$sourceName, $canonicalName]) {
                $rows[] = [$studentCounts[$route] + $offset + 1, $sourceName, 'сотр', "  Staff stop {$route} {$offset}  ", '', ''];
                User::factory()->create(['name' => $canonicalName, 'is_active' => true]);
            }
            $path = $directory.'/Трансфер_'.$route.'.xlsx';
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->fromArray($rows);
            (new Xlsx($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();
            $paths[] = $path;
        }

        return $paths;
    }

    private function counts(): array
    {
        return [
            'students' => Student::count(), 'enrollments' => Enrollment::count(),
            'student_assignments' => StudentTransportAssignment::count(), 'staff_assignments' => VehicleStaffAssignment::count(),
            'audits' => DB::table('audit_logs')->count(), 'fee_prices' => DB::table('fee_prices')->count(),
            'invoices' => DB::table('invoices')->count(), 'invoice_items' => DB::table('invoice_items')->count(),
            'invoice_payments' => DB::table('invoice_payments')->count(), 'subscriptions' => DB::table('student_service_subscriptions')->count(),
        ];
    }

    private function studentAssignmentHash(): string
    {
        return hash('sha256', StudentTransportAssignment::orderBy('id')->get()->toJson());
    }
}
