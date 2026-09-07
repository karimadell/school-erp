<?php

namespace Tests\Feature\Transport;

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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransportManagementUiTest extends TestCase
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
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole('admin');
        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->stage = Stage::create(['name' => 'Школа', 'order' => 1, 'is_active' => true]);
        $this->grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $this->stage->id, 'level' => 1]);
        $this->class = SchoolClass::create(['grade_id' => $this->grade->id, 'code' => 'А', 'name_ru' => 'А', 'name_ar' => 'أ', 'is_active' => true]);
    }

    public function test_page_navigation_permission_and_overview_excludes_staff_from_seats(): void
    {
        [$bus, $route] = $this->transport();
        app(TransportAssignmentService::class)->assign($this->enrollment('Ученик'), $route, $bus, ['effective_from' => '2026-09-01'], $this->actor);
        VehicleStaffAssignment::create(['bus_id' => $bus->id, 'user_id' => User::factory()->create(['is_active' => true])->id, 'role' => 'staff_passenger', 'effective_from' => '2026-09-01', 'created_by' => $this->actor->id]);

        $this->actingAs($this->actor)->get(route('dashboard.transport-management.index', ['date' => '2026-09-07']))
            ->assertOk()->assertSee('Управление трансфером')->assertSee('1 / 14 мест')->assertSee('13');

        $unauthorized = User::factory()->create(['is_active' => true]);
        $unauthorized->assignRole('reception');
        $this->actingAs($unauthorized)->get(route('dashboard.transport-management.index'))->assertForbidden();
    }

    public function test_vehicle_and_route_catalog_actions_are_audited_and_have_no_price_authority(): void
    {
        $this->actingAs($this->actor)->post(route('dashboard.transport-management.vehicles.store'), [
            'vehicle_code' => 'BUS-1', 'name' => 'Микроавтобус 1', 'plate_number' => 'A-123', 'student_capacity' => 14, 'passenger_capacity' => 15,
        ])->assertRedirect();
        $bus = Bus::where('vehicle_code', 'BUS-1')->firstOrFail();
        $this->assertNull($bus->driver_name);
        $this->actingAs($this->actor)->put(route('dashboard.transport-management.vehicles.update', $bus), [
            'vehicle_code' => 'BUS-1', 'name' => 'Первый', 'plate_number' => 'A-123', 'student_capacity' => 13, 'passenger_capacity' => 15,
        ])->assertRedirect();
        $this->actingAs($this->actor)->patch(route('dashboard.transport-management.vehicles.active', $bus), ['is_active' => 0])->assertRedirect();
        $this->assertFalse($bus->fresh()->is_active);

        $this->actingAs($this->actor)->post(route('dashboard.transport-management.routes.store'), ['name' => 'Бритиш', 'pricing_zone' => null, 'description' => 'Физический маршрут'])->assertRedirect();
        $route = TransportRoute::where('name', 'Бритиш')->firstOrFail();
        $this->actingAs($this->actor)->put(route('dashboard.transport-management.routes.update', $route), ['name' => 'Бритиш', 'pricing_zone' => 'Zone X', 'description' => 'Обновлено'])->assertRedirect();
        $this->actingAs($this->actor)->patch(route('dashboard.transport-management.routes.active', $route), ['is_active' => 0])->assertRedirect();
        $this->assertFalse($route->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transport_vehicle_created', 'model_id' => $bus->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transport_route_created', 'model_id' => $route->id]);

        $this->actingAs($this->actor)->get(route('dashboard.transport-management.index'))->assertOk()->assertDontSee('name="price"', false);
    }

    public function test_student_ui_assigns_fourteenth_rejects_fifteenth_and_never_writes_legacy_or_finance(): void
    {
        [$bus, $route] = $this->transport();
        foreach (range(1, 13) as $number) {
            app(TransportAssignmentService::class)->assign($this->enrollment("Ученик {$number}"), $route, $bus, ['effective_from' => '2026-09-01'], $this->actor);
        }
        $before = $this->protectedCounts();
        $fourteenth = $this->enrollment('Четырнадцатый');
        $this->actingAs($this->actor)->post(route('dashboard.transport-management.student-assignments.store'), [
            'enrollment_id' => $fourteenth->id, 'pricing_zone' => 'Zone 1', 'transport_route_id' => $route->id, 'bus_id' => $bus->id,
            'pickup_point' => '  Остановка Как В Источнике  ', 'effective_from' => '2026-09-01',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('  Остановка Как В Источнике  ', StudentTransportAssignment::where('enrollment_id', $fourteenth->id)->value('pickup_point'));

        $fifteenth = $this->enrollment('Пятнадцатый');
        $this->actingAs($this->actor)->from(route('dashboard.transport-management.index'))->post(route('dashboard.transport-management.student-assignments.store'), [
            'enrollment_id' => $fifteenth->id, 'pricing_zone' => 'Zone 1', 'transport_route_id' => $route->id, 'bus_id' => $bus->id, 'effective_from' => '2026-09-01',
        ])->assertRedirect()->assertSessionHasErrors('bus_id');
        $this->assertDatabaseMissing('student_transport_assignments', ['enrollment_id' => $fifteenth->id]);
        $this->assertSame($before, $this->protectedCounts());
    }

    public function test_same_zone_transfer_and_stop_preserve_history(): void
    {
        [$source, $route] = $this->transport();
        $destination = Bus::create(['vehicle_code' => 'BUS-2', 'plate_number' => 'B-2', 'is_active' => true]);
        $newRoute = TransportRoute::create(['name' => 'Каусер', 'pricing_zone' => 'Zone 1', 'is_active' => true]);
        $old = app(TransportAssignmentService::class)->assign($this->enrollment('Перевод'), $route, $source, ['pricing_zone' => 'Zone 1', 'effective_from' => '2026-09-01'], $this->actor);

        $this->actingAs($this->actor)->post(route('dashboard.transport-management.student-assignments.transfer', $old), [
            'pricing_zone' => 'Zone 1', 'transport_route_id' => $newRoute->id, 'bus_id' => $destination->id,
            'pickup_point' => 'Новая точка', 'effective_from' => '2026-10-01', 'change_reason' => 'Новый автобус',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $new = StudentTransportAssignment::where('enrollment_id', $old->enrollment_id)->latest('id')->firstOrFail();
        $this->assertSame('2026-09-30', $old->fresh()->effective_to->toDateString());
        $this->assertSame(2, StudentTransportAssignment::where('enrollment_id', $old->enrollment_id)->count());

        $this->actingAs($this->actor)->post(route('dashboard.transport-management.student-assignments.end', $new), ['effective_to' => '2026-12-31', 'change_reason' => 'Отказ'])->assertRedirect();
        $this->assertSame('ended', $new->fresh()->status);
        $this->assertSame(2, StudentTransportAssignment::where('enrollment_id', $old->enrollment_id)->count());
    }

    public function test_zone_change_is_blocked_without_finance_mutation(): void
    {
        [$bus, $route] = $this->transport();
        $assignment = app(TransportAssignmentService::class)->assign($this->enrollment('Зона'), $route, $bus, ['pricing_zone' => 'Zone 1', 'effective_from' => '2026-09-01'], $this->actor);
        $before = $this->protectedCounts();
        $this->actingAs($this->actor)->from(route('dashboard.transport-management.index'))->post(route('dashboard.transport-management.student-assignments.transfer', $assignment), [
            'pricing_zone' => 'Zone 2', 'transport_route_id' => $route->id, 'bus_id' => $bus->id, 'effective_from' => '2026-10-01', 'change_reason' => 'Другая зона',
        ])->assertRedirect()->assertSessionHasErrors('pricing_zone');
        $this->assertNull($assignment->fresh()->effective_to);
        $this->assertSame($before, $this->protectedCounts());
    }

    public function test_staff_ui_supports_roles_multiple_passengers_weekdays_and_prevents_supervisor_conflict(): void
    {
        [$bus] = $this->transport();
        foreach (['driver', 'supervisor', 'staff_passenger', 'staff_passenger'] as $index => $role) {
            $this->actingAs($this->actor)->post(route('dashboard.transport-management.staff-assignments.store'), [
                'bus_id' => $bus->id, 'user_id' => User::factory()->create(['is_active' => true])->id, 'role' => $role,
                'effective_from' => '2026-09-01', 'weekdays' => $role === 'supervisor' ? [1, 4] : null,
            ])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame([1, 4], VehicleStaffAssignment::where('role', 'supervisor')->firstOrFail()->weekdays);
        $this->assertSame(2, VehicleStaffAssignment::where('role', 'staff_passenger')->count());

        $this->actingAs($this->actor)->from(route('dashboard.transport-management.index'))->post(route('dashboard.transport-management.staff-assignments.store'), [
            'bus_id' => $bus->id, 'user_id' => User::factory()->create(['is_active' => true])->id, 'role' => 'supervisor', 'effective_from' => '2026-09-01', 'weekdays' => [4],
        ])->assertRedirect()->assertSessionHasErrors('role');

        $driver = VehicleStaffAssignment::where('role', 'driver')->firstOrFail();
        $replacement = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->actor)->post(route('dashboard.transport-management.staff-assignments.change', $driver), [
            'bus_id' => $bus->id, 'user_id' => $replacement->id, 'role' => 'driver',
            'effective_from' => '2026-10-01', 'weekdays' => [1, 4], 'change_reason' => 'Смена водителя',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $newDriver = VehicleStaffAssignment::where('role', 'driver')->latest('id')->firstOrFail();
        $this->assertSame('2026-09-30', $driver->fresh()->effective_to->toDateString());
        $this->assertSame([1, 4], $newDriver->weekdays);
        $this->actingAs($this->actor)->post(route('dashboard.transport-management.staff-assignments.end', $newDriver), [
            'effective_to' => '2026-12-31', 'change_reason' => 'Завершение',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-12-31', $newDriver->fresh()->effective_to->toDateString());
    }

    public function test_history_filters_and_server_side_write_permissions(): void
    {
        [$bus, $route] = $this->transport();
        app(TransportAssignmentService::class)->assign($this->enrollment('История Иванов'), $route, $bus, ['pricing_zone' => 'Zone 1', 'pickup_point' => 'Точка', 'effective_from' => '2026-09-01'], $this->actor);
        $this->actingAs($this->actor)->get(route('dashboard.transport-management.index', ['academic_year_id' => $this->year->id, 'bus_id' => $bus->id, 'route_id' => $route->id, 'student' => 'Иванов', 'history_date' => '2026-09-07']))
            ->assertOk()->assertSee('История Иванов')->assertSee('Точка');

        $unauthorized = User::factory()->create(['is_active' => true]);
        $unauthorized->assignRole('reception');
        $this->actingAs($unauthorized)->post(route('dashboard.transport-management.vehicles.store'), ['plate_number' => 'NO', 'student_capacity' => 14, 'passenger_capacity' => 15])->assertForbidden();
        $this->actingAs($unauthorized)->post(route('dashboard.transport-management.routes.store'), ['name' => 'NO'])->assertForbidden();
        $this->actingAs($unauthorized)->post(route('dashboard.transport-management.student-assignments.store'), [])->assertForbidden();
        $this->actingAs($unauthorized)->post(route('dashboard.transport-management.staff-assignments.store'), [])->assertForbidden();
    }

    private function transport(): array
    {
        return [
            Bus::create(['vehicle_code' => uniqid('BUS-'), 'plate_number' => uniqid('P-'), 'is_active' => true]),
            TransportRoute::create(['name' => uniqid('Маршрут '), 'pricing_zone' => 'Zone 1', 'is_active' => true]),
        ];
    }

    private function enrollment(string $name): Enrollment
    {
        $student = Student::create(['name' => $name, 'status' => Student::STATUS_ACTIVE]);

        return Enrollment::create(['student_id' => $student->id, 'academic_year_id' => $this->year->id, 'stage_id' => $this->stage->id, 'grade_id' => $this->grade->id, 'class_id' => $this->class->id, 'enrolled_at' => '2026-09-01', 'status' => 'active', 'is_active' => true]);
    }

    private function protectedCounts(): array
    {
        return [
            'legacy_subscriptions' => DB::table('transport_subscriptions')->count(), 'legacy_students' => DB::table('bus_student')->count(),
            'legacy_routes' => DB::table('bus_routes')->count(), 'legacy_stops' => DB::table('bus_stops')->count(),
            'prices' => FeePrice::count(), 'invoices' => Invoice::count(), 'payments' => InvoicePayment::count(),
        ];
    }
}
