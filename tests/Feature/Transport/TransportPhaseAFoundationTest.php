<?php

namespace Tests\Feature\Transport;

use App\Exceptions\TransportCapacityExceeded;
use App\Models\AcademicYear;
use App\Models\Bus;
use App\Models\Enrollment;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\User;
use App\Models\VehicleStaffAssignment;
use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\VehicleStaffAssignmentService;
use App\Support\TransportPermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransportPhaseAFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private AcademicYear $year;

    private Stage $stage;

    private Grade $grade;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole('admin');
        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->stage = Stage::create(['name' => 'Школа', 'order' => 1, 'is_active' => true]);
        $this->grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $this->stage->id, 'level' => 1]);
        $this->class = SchoolClass::create(['grade_id' => $this->grade->id, 'code' => 'А', 'name_ru' => 'А', 'name_ar' => 'أ', 'is_active' => true]);
    }

    public function test_bus_defaults_and_legacy_fields_are_preserved(): void
    {
        $bus = Bus::create(['plate_number' => 'LEGACY-1', 'driver_name' => 'Старое имя', 'capacity' => 30, 'is_active' => true]);
        $bus->refresh();
        $this->assertSame(14, $bus->student_capacity);
        $this->assertSame(15, $bus->passenger_capacity);
        $this->assertSame(30, $bus->capacity);
        $this->assertSame('Старое имя', $bus->driver_name);
        $this->assertTrue(Schema::hasColumns('buses', ['vehicle_code', 'name', 'student_capacity', 'passenger_capacity', 'driver_name']));

        $this->expectException(ValidationException::class);
        Bus::create(['vehicle_code' => 'TOO-LARGE', 'student_capacity' => 15, 'passenger_capacity' => 15, 'is_active' => true]);
    }

    public function test_route_has_nullable_non_authoritative_zone_and_no_price(): void
    {
        $route = TransportRoute::create(['name' => 'Бритиш', 'pricing_zone' => null, 'is_active' => true]);
        $this->assertNull($route->pricing_zone);
        $this->assertFalse(Schema::hasColumn('transport_routes', 'price'));
    }

    public function test_fourteen_students_succeed_fifteenth_is_rejected_and_staff_does_not_count(): void
    {
        [$bus, $route] = $this->transport();
        $staff = User::factory()->create(['is_active' => true]);
        app(VehicleStaffAssignmentService::class)->assign($bus, $staff, VehicleStaffAssignment::ROLE_STAFF_PASSENGER, '2026-09-01', null, null, $this->actor);
        app(VehicleStaffAssignmentService::class)->assign($bus, User::factory()->create(['is_active' => true]), VehicleStaffAssignment::ROLE_STAFF_PASSENGER, '2026-09-01', null, [1, 4], $this->actor);

        foreach (range(1, 14) as $number) {
            app(TransportAssignmentService::class)->assign($this->enrollment("Ученик {$number}"), $route, $bus, ['effective_from' => '2026-09-01'], $this->actor);
        }
        $this->assertSame(14, StudentTransportAssignment::where('bus_id', $bus->id)->count());
        $this->expectException(TransportCapacityExceeded::class);
        app(TransportAssignmentService::class)->assign($this->enrollment('Пятнадцатый'), $route, $bus, ['effective_from' => '2026-09-01'], $this->actor);
    }

    public function test_assignment_uses_enrollment_rejects_overlap_wrong_year_and_inactive_entities(): void
    {
        [$bus, $route] = $this->transport();
        $enrollment = $this->enrollment('Иванов');
        $assignment = app(TransportAssignmentService::class)->assign($enrollment, $route, $bus, ['effective_from' => '2026-09-01', 'pickup_point' => '  Каусер Сити  '], $this->actor);
        $this->assertSame($enrollment->id, $assignment->enrollment_id);
        $this->assertSame('  Каусер Сити  ', $assignment->pickup_point);

        try {
            app(TransportAssignmentService::class)->assign($enrollment, $route, $bus, ['effective_from' => '2026-10-01'], $this->actor);
            $this->fail('Overlapping assignment accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('enrollment_id', $e->errors());
        }
        foreach ([[$bus->replicate()->fill(['vehicle_code' => 'INACTIVE', 'is_active' => false]), $route], [$bus, $route->replicate()->fill(['is_active' => false])]] as [$candidateBus, $candidateRoute]) {
            $candidateBus->save();
            $candidateRoute->save();
            try {
                app(TransportAssignmentService::class)->assign($this->enrollment(uniqid('S')), $candidateRoute, $candidateBus, ['effective_from' => '2026-09-01'], $this->actor);
                $this->fail('Inactive entity accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        try {
            app(TransportAssignmentService::class)->assign($this->enrollment('Wrong date'), $route, $bus, ['effective_from' => '2027-09-01'], $this->actor);
            $this->fail('Wrong year date accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_transfer_preserves_history_for_route_vehicle_and_future_changes(): void
    {
        [$source, $route] = $this->transport();
        $destination = Bus::create(['vehicle_code' => 'B-2', 'student_capacity' => 14, 'passenger_capacity' => 15, 'is_active' => true]);
        $otherRoute = TransportRoute::create(['name' => 'Каусер', 'pricing_zone' => 'Каусер, Мубарак 2, Интерконтиненталь', 'is_active' => true]);
        $old = app(TransportAssignmentService::class)->assign($this->enrollment('Перевод'), $route, $source, ['effective_from' => '2026-09-01'], $this->actor);
        $new = app(TransportAssignmentService::class)->transfer($old, $otherRoute, $destination, '2026-11-01', ['change_reason' => 'Смена маршрута'], $this->actor);
        $this->assertSame('2026-10-31', $old->fresh()->effective_to->toDateString());
        $this->assertSame(StudentTransportAssignment::STATUS_ENDED, $old->fresh()->status);
        $this->assertSame('2026-11-01', $new->effective_from->toDateString());
        $this->assertSame(2, StudentTransportAssignment::where('enrollment_id', $old->enrollment_id)->count());

        $sameRouteNewBus = Bus::create(['vehicle_code' => 'B-3', 'is_active' => true]);
        $third = app(TransportAssignmentService::class)->transfer($new, $otherRoute, $sameRouteNewBus, '2027-01-01', [], $this->actor);
        $this->assertSame($otherRoute->id, $third->transport_route_id);
        $this->assertSame(3, StudentTransportAssignment::where('enrollment_id', $old->enrollment_id)->count());
    }

    public function test_transfer_enforces_destination_capacity(): void
    {
        [$source, $route] = $this->transport();
        $destination = Bus::create(['vehicle_code' => 'FULL', 'is_active' => true]);
        foreach (range(1, 14) as $number) {
            app(TransportAssignmentService::class)->assign($this->enrollment("Full {$number}"), $route, $destination, ['effective_from' => '2026-09-01'], $this->actor);
        }
        $old = app(TransportAssignmentService::class)->assign($this->enrollment('Move'), $route, $source, ['effective_from' => '2026-09-01'], $this->actor);
        try {
            app(TransportAssignmentService::class)->transfer($old, $route, $destination, '2026-10-01', [], $this->actor);
            $this->fail('Full destination accepted.');
        } catch (TransportCapacityExceeded) {
        }
        $this->assertNull($old->fresh()->effective_to);
        $this->assertSame(1, StudentTransportAssignment::where('enrollment_id', $old->enrollment_id)->count());
    }

    public function test_staff_roles_weekdays_multiple_passengers_and_history(): void
    {
        [$bus] = $this->transport();
        $service = app(VehicleStaffAssignmentService::class);
        $mondayThursday = $service->assign($bus, User::factory()->create(['is_active' => true]), VehicleStaffAssignment::ROLE_SUPERVISOR, '2026-09-01', null, [4, 1, 4], $this->actor);
        $this->assertSame([1, 4], $mondayThursday->weekdays);
        $service->assign($bus, User::factory()->create(['is_active' => true]), VehicleStaffAssignment::ROLE_DRIVER, '2026-09-01', null, null, $this->actor);
        $service->assign($bus, User::factory()->create(['is_active' => true]), VehicleStaffAssignment::ROLE_STAFF_PASSENGER, '2026-09-01', null, [1], $this->actor);
        $service->assign($bus, User::factory()->create(['is_active' => true]), VehicleStaffAssignment::ROLE_STAFF_PASSENGER, '2026-09-01', null, [1], $this->actor);
        $this->assertCount(4, $service->activeForDate($bus, '2026-09-07')); // Monday
        $this->assertCount(1, $service->activeForDate($bus, '2026-09-08')); // Tuesday: all-days driver only
        $ended = $service->end($mondayThursday, '2026-12-31', $this->actor, 'Замена');
        $this->assertSame('2026-12-31', $ended->effective_to->toDateString());
        $this->assertSame($mondayThursday->user_id, $ended->user_id);
    }

    public function test_supervisor_conflicts_only_on_overlapping_weekdays(): void
    {
        [$bus] = $this->transport();
        $service = app(VehicleStaffAssignmentService::class);
        $service->assign($bus, User::factory()->create(['is_active' => true]), 'supervisor', '2026-09-01', null, [1, 4], $this->actor);
        $service->assign($bus, User::factory()->create(['is_active' => true]), 'supervisor', '2026-09-01', null, [2], $this->actor);
        $this->expectException(ValidationException::class);
        $service->assign($bus, User::factory()->create(['is_active' => true]), 'supervisor', '2026-09-01', null, [4], $this->actor);
    }

    public function test_legacy_and_finance_tables_receive_no_writes(): void
    {
        [$bus, $route] = $this->transport();
        $before = ['transport_subscriptions' => DB::table('transport_subscriptions')->count(), 'bus_student' => DB::table('bus_student')->count(), 'bus_routes' => DB::table('bus_routes')->count(), 'bus_stops' => DB::table('bus_stops')->count(), 'fee_prices' => FeePrice::count(), 'invoices' => Invoice::count(), 'payments' => InvoicePayment::count()];
        app(TransportAssignmentService::class)->assign($this->enrollment('Без финансов'), $route, $bus, ['effective_from' => '2026-09-01'], $this->actor);
        $after = ['transport_subscriptions' => DB::table('transport_subscriptions')->count(), 'bus_student' => DB::table('bus_student')->count(), 'bus_routes' => DB::table('bus_routes')->count(), 'bus_stops' => DB::table('bus_stops')->count(), 'fee_prices' => FeePrice::count(), 'invoices' => Invoice::count(), 'payments' => InvoicePayment::count()];
        $this->assertSame($before, $after);
        $this->assertTrue(Schema::hasTable('transport_subscriptions'));
        $this->assertTrue(Schema::hasTable('bus_student'));
    }

    public function test_legacy_transport_controller_is_explicitly_write_locked(): void
    {
        $before = DB::table('transport_routes')->count();
        $this->actingAs($this->actor)->post(route('dashboard.transport.store'), [
            'name' => 'Нельзя создать', 'capacity' => 14,
        ])->assertGone();
        $this->assertSame($before, DB::table('transport_routes')->count());
    }

    public function test_permissions_and_audit_events_are_foundationally_enforced(): void
    {
        foreach (TransportPermissions::ALL as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission]);
        }
        [$bus, $route] = $this->transport();
        $assignment = app(TransportAssignmentService::class)->assign($this->enrollment('Аудит'), $route, $bus, ['effective_from' => '2026-09-01'], $this->actor);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_transport_assigned', 'model_id' => $assignment->id, 'user_id' => $this->actor->id]);

        $unauthorized = User::factory()->create(['is_active' => true]);
        try {
            app(TransportAssignmentService::class)->assign($this->enrollment('Нет прав'), $route, $bus, ['effective_from' => '2026-09-01'], $unauthorized);
            $this->fail('Unauthorized assignment accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function transport(): array
    {
        return [Bus::create(['vehicle_code' => uniqid('BUS-'), 'is_active' => true]), TransportRoute::create(['name' => uniqid('Маршрут '), 'is_active' => true])];
    }

    private function enrollment(string $name): Enrollment
    {
        $student = Student::create(['name' => $name, 'status' => Student::STATUS_ACTIVE]);

        return Enrollment::create(['student_id' => $student->id, 'academic_year_id' => $this->year->id, 'stage_id' => $this->stage->id, 'grade_id' => $this->grade->id, 'class_id' => $this->class->id, 'enrolled_at' => '2026-09-01', 'status' => 'active', 'is_active' => true]);
    }
}
