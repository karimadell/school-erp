<?php

namespace Tests\Feature\Transport;

use App\Models\AcademicYear;
use App\Models\Bus;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use App\Services\Transport\TransportAssignmentService;
use App\Support\TransportPermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Transport capacity decoupling (corrects the original Transport Management
 * Phase C integration, which is what this file used to test).
 *
 * The authoritative business rule: at Quick Registration time the school
 * often does not yet know the final vehicle/route assignment, and a student
 * must be accepted and billed for Transport even if the currently selected
 * route/vehicle is already at capacity. Final seat assignment is always a
 * later, separate Operations action (TransportManagementController ->
 * TransportAssignmentService::assign(), unchanged, still capacity-enforced
 * there). Quick Registration now only creates the same generic
 * StudentServiceSubscription every other service category uses — its
 * metadata (area/route_id/route/stop/payment_period) carries everything
 * Operations needs to assign a real seat later. No StudentTransportAssignment
 * row, no Bus involvement, no capacity check, no TransportPermissions::
 * MANAGE_ASSIGNMENTS requirement, is reachable from this controller any more.
 */
class QuickRegistrationTransportIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private AcademicYear $year;

    private Stage $stage;

    private Grade $grade;

    private SchoolClass $class;

    private EnrollmentMode $mode;

    private CashAccount $account;

    private Fee $transportFee;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');

        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->stage = Stage::create(['name' => 'Школа', 'order' => 1, 'is_active' => true]);
        $this->grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $this->stage->id, 'level' => 1]);
        $this->class = SchoolClass::create(['grade_id' => $this->grade->id, 'code' => 'А', 'name_ru' => 'А', 'name_ar' => 'A', 'is_active' => true]);
        $this->mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очная форма', 'is_active' => true]);
        $this->account = CashAccount::operating();
        app(CashSessionService::class)->open($this->account, $this->accountant);

        $this->transportFee = Fee::create(['name_ru' => 'Трансфер', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '0.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $this->transportFee->id, 'academic_year_id' => $this->year->id, 'amount' => '1500.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly',
        ]);
    }

    private function route(?string $pricingZone = 'Зона 1', bool $isActive = true): TransportRoute
    {
        return TransportRoute::create(['name' => 'Маршрут 1', 'pricing_zone' => $pricingZone, 'is_active' => $isActive]);
    }

    private function bus(): Bus
    {
        return Bus::create(['vehicle_code' => uniqid('BUS-'), 'is_active' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'student_last_name_ru' => 'Иванов', 'student_first_name_ru' => 'Иван',
            'phone' => '+201000000000', 'registration_date' => '2026-09-05',
            'academic_year_id' => $this->year->id, 'stage_id' => $this->stage->id,
            'grade_id' => $this->grade->id, 'class_id' => $this->class->id,
            'enrollment_mode_id' => $this->mode->id,
            'cash_account_id' => $this->account->id, 'payment_method' => 'cash',
        ], $overrides);
    }

    /** No bus_id: no longer part of the Quick Registration Transport payload at all. */
    private function transportService(TransportRoute $route, array $overrides = []): array
    {
        return array_replace([
            'fee_id' => $this->transportFee->id, 'quantity' => 1, 'paid_now' => '0.00',
            'transport_area' => 'Зона 1', 'transport_route_id' => $route->id,
            'payment_period' => 'monthly', 'transport_stop' => 'У ворот',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // 1. Quick Registration without Transport unchanged.
    // ------------------------------------------------------------------
    public function test_quick_registration_without_transport_is_unchanged(): void
    {
        $registration = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1000.00', 'is_active' => true]);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Student::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(0, StudentTransportAssignment::count());
    }

    // ------------------------------------------------------------------
    // 2. Transport QR creates NO StudentTransportAssignment — only a
    //    StudentServiceSubscription carrying the demand metadata Operations
    //    will need for the later, separate seat-assignment action.
    // ------------------------------------------------------------------
    public function test_transport_registration_creates_no_seat_assignment_but_correct_subscription_metadata(): void
    {
        $route = $this->route();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route, ['transport_stop' => 'У ворот', 'payment_period' => 'monthly'])],
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0, StudentTransportAssignment::count(), 'Quick Registration must never create a real seat assignment.');
        $subscription = StudentServiceSubscription::sole();
        $enrollment = Enrollment::sole();
        $this->assertSame($enrollment->id, $subscription->enrollment_id);
        $this->assertSame($this->transportFee->id, $subscription->fee_id);
        $this->assertSame('Зона 1', $subscription->metadata['area']);
        $this->assertSame($route->id, $subscription->metadata['route_id']);
        $this->assertSame($route->name, $subscription->metadata['route']);
        $this->assertSame('У ворот', $subscription->metadata['stop']);
        $this->assertSame('monthly', $subscription->metadata['payment_period']);
        $this->assertSame(StudentServiceSubscription::STATUS_ACTIVE, $subscription->status);
    }

    // ------------------------------------------------------------------
    // 3. Invoice amount unchanged, zone-priced, independent of route.
    // ------------------------------------------------------------------
    public function test_invoice_amount_is_zone_priced_regardless_of_route(): void
    {
        $routeA = $this->route();
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($routeA)],
        ]));
        $response->assertSessionHasNoErrors();
        $this->assertSame('1500.00', Invoice::sole()->total_amount);

        // A different route, same zone -> identical price.
        $routeB = TransportRoute::create(['name' => 'Другой маршрут', 'pricing_zone' => null, 'is_active' => true]);
        $response2 = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'student_last_name_ru' => 'Петров', 'student_first_name_ru' => 'Пётр',
            'services' => [$this->transportService($routeB)],
        ]));
        $response2->assertSessionHasNoErrors();
        $this->assertSame(2, Invoice::count());
        $this->assertSame('1500.00', Invoice::latest('id')->first()->total_amount);
    }

    // ------------------------------------------------------------------
    // 4. Mixed billing unchanged, still creates no assignment.
    // ------------------------------------------------------------------
    public function test_mixed_billing_with_transport_and_registration_unchanged(): void
    {
        $registration = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1000.00', 'is_active' => true]);
        $route = $this->route();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [
                ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00'],
                $this->transportService($route),
            ],
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('2500.00', Invoice::sole()->total_amount);
        $this->assertSame(2, InvoiceItem::count());
        $this->assertSame(0, StudentTransportAssignment::count());
    }

    // ------------------------------------------------------------------
    // 5. Coverage/payment allocation unchanged.
    // ------------------------------------------------------------------
    public function test_transport_subscription_and_payment_still_created_as_before(): void
    {
        $route = $this->route();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route, ['paid_now' => '1500.00'])],
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, StudentServiceSubscription::count());
        $invoice = Invoice::sole();
        $this->assertSame('1500.00', $invoice->paid_amount);
        $this->assertSame(1, $invoice->payments()->count());
    }

    // ------------------------------------------------------------------
    // 6. THE REGRESSION: a route/vehicle already at full capacity must
    //    never block Quick Registration, never throw TransportCapacityExceeded,
    //    and must never create a seat assignment. This is the exact original
    //    UAT failure ("Passenger capacity 15 exceeded...") made impossible.
    // ------------------------------------------------------------------
    public function test_registration_succeeds_when_the_route_vehicle_is_already_at_full_capacity(): void
    {
        $route = $this->route();
        $bus = $this->bus();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        // Fill the bus to its real capacity (14 students, the same
        // student_capacity ceiling TransportPassengerCapacityService
        // enforces) via the Operations assignment path directly — proving
        // this scenario is a genuinely full vehicle, not a contrived one.
        foreach (range(1, 14) as $n) {
            $student = Student::create(['name' => "Filler {$n}", 'status' => Student::STATUS_ACTIVE]);
            $enrollment = Enrollment::create(['student_id' => $student->id, 'academic_year_id' => $this->year->id, 'stage_id' => $this->stage->id, 'grade_id' => $this->grade->id, 'class_id' => $this->class->id, 'enrolled_at' => '2026-09-01', 'status' => 'active', 'is_active' => true]);
            app(TransportAssignmentService::class)->assign($enrollment, $route, $bus, ['effective_from' => '2026-09-01'], $admin);
        }
        $this->assertSame(14, StudentTransportAssignment::where('bus_id', $bus->id)->count(), 'the vehicle must genuinely be full before this test proves anything');

        $studentCountBefore = Student::count();

        // The 15th student — via the full Quick Registration HTTP flow —
        // must succeed even though the only vehicle on this route is full.
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'student_last_name_ru' => 'Пятнадцатый', 'student_first_name_ru' => 'Ученик',
            'services' => [$this->transportService($route)],
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($studentCountBefore + 1, Student::count());
        $this->assertSame(1, Enrollment::where('student_id', Student::where('last_name_ru', 'Пятнадцатый')->value('id'))->count());
        $this->assertSame(1, Invoice::where('student_id', Student::where('last_name_ru', 'Пятнадцатый')->value('id'))->count());
        $newInvoice = Invoice::whereHas('student', fn ($q) => $q->where('last_name_ru', 'Пятнадцатый'))->sole();
        $this->assertSame('1500.00', $newInvoice->total_amount);
        $this->assertSame(1, StudentServiceSubscription::whereHas('enrollment.student', fn ($q) => $q->where('last_name_ru', 'Пятнадцатый'))->count());
        // The vehicle's real seat count is untouched — Quick Registration
        // never wrote to student_transport_assignments.
        $this->assertSame(14, StudentTransportAssignment::where('bus_id', $bus->id)->count());
    }

    // ------------------------------------------------------------------
    // 7. Inactive route still rejected — preserved, unrelated-to-capacity
    //    validation, now enforced by StoreQuickStudentRegistrationRequest
    //    directly instead of TransportAssignmentService::validate().
    // ------------------------------------------------------------------
    public function test_inactive_route_is_rejected_and_rolls_back(): void
    {
        $route = $this->route(pricingZone: null, isActive: false);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route, ['transport_area' => 'Зона 1'])],
        ]));

        $response->assertSessionHasErrors(['services.0.transport_route_id']);
        $this->assertSame(0, Student::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, StudentServiceSubscription::count());
        $this->assertSame(0, StudentTransportAssignment::count());
    }

    // ------------------------------------------------------------------
    // 8. route/zone mismatch rejected. 9. nullable route zone + explicit
    //    valid zone succeeds (now proven via subscription metadata, since
    //    there is no StudentTransportAssignment any more).
    // ------------------------------------------------------------------
    public function test_route_zone_mismatch_is_rejected_by_validation(): void
    {
        $route = $this->route('Зона 2'); // route belongs to a different zone than submitted

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route, ['transport_area' => 'Зона 1'])],
        ]));

        $response->assertSessionHasErrors(['services.0.transport_route_id']);
        $this->assertSame(0, Student::count());
        $this->assertSame(0, StudentServiceSubscription::count());
    }

    public function test_nullable_route_zone_with_explicit_valid_zone_succeeds(): void
    {
        $route = $this->route(null);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route, ['transport_area' => 'Зона 1'])],
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Зона 1', StudentServiceSubscription::sole()->metadata['area']);
    }

    // ------------------------------------------------------------------
    // 10. Retry does not duplicate the subscription.
    // ------------------------------------------------------------------
    public function test_retry_with_same_idempotency_token_does_not_duplicate_subscription(): void
    {
        $route = $this->route();
        $token = 'retry-token-'.uniqid();

        $payload = $this->payload([
            'idempotency_token' => $token,
            'services' => [$this->transportService($route)],
        ]);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, Student::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, StudentServiceSubscription::count());
        $this->assertSame(0, StudentTransportAssignment::count());
    }

    // ------------------------------------------------------------------
    // 11. A user who holds ONLY 'manage invoices' (no Transport assignment
    //     permission at all) can now successfully register a Transport
    //     student — TransportPermissions::MANAGE_ASSIGNMENTS is no longer
    //     reachable from Quick Registration, by design: it remains the
    //     gate for Operations' real seat-assignment action only.
    // ------------------------------------------------------------------
    public function test_a_user_without_transport_assignment_permission_can_still_register_transport_billing(): void
    {
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole('cashier');
        $this->assertTrue($operator->can('manage invoices'));
        $this->assertFalse($operator->can(TransportPermissions::MANAGE_ASSIGNMENTS));
        $route = $this->route();

        $response = $this->actingAs($operator)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route)],
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Student::count());
        $this->assertSame(1, StudentServiceSubscription::count());
        $this->assertSame(0, StudentTransportAssignment::count());
    }

    // ------------------------------------------------------------------
    // 12. Operations' real seat assignment still enforces capacity —
    //     completely unchanged by this decoupling.
    // ------------------------------------------------------------------
    public function test_operations_seat_assignment_still_enforces_capacity_after_decoupling(): void
    {
        $route = $this->route();
        $bus = $this->bus();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        foreach (range(1, 14) as $n) {
            $student = Student::create(['name' => "Filler {$n}", 'status' => Student::STATUS_ACTIVE]);
            $enrollment = Enrollment::create(['student_id' => $student->id, 'academic_year_id' => $this->year->id, 'stage_id' => $this->stage->id, 'grade_id' => $this->grade->id, 'class_id' => $this->class->id, 'enrolled_at' => '2026-09-01', 'status' => 'active', 'is_active' => true]);
            app(TransportAssignmentService::class)->assign($enrollment, $route, $bus, ['effective_from' => '2026-09-01'], $admin);
        }

        $student15 = Student::create(['name' => 'Fifteenth', 'status' => Student::STATUS_ACTIVE]);
        $enrollment15 = Enrollment::create(['student_id' => $student15->id, 'academic_year_id' => $this->year->id, 'stage_id' => $this->stage->id, 'grade_id' => $this->grade->id, 'class_id' => $this->class->id, 'enrolled_at' => '2026-09-01', 'status' => 'active', 'is_active' => true]);

        $this->expectException(\App\Exceptions\TransportCapacityExceeded::class);
        app(TransportAssignmentService::class)->assign($enrollment15, $route, $bus, ['effective_from' => '2026-09-01'], $admin);
    }

    // ------------------------------------------------------------------
    // 13. No legacy table writes.
    // ------------------------------------------------------------------
    public function test_no_legacy_transport_table_writes(): void
    {
        $route = $this->route();

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route)],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('transport_subscriptions')->count());
        $this->assertSame(0, DB::table('bus_student')->count());
        $this->assertSame(0, DB::table('bus_stops')->count());
    }

    // ------------------------------------------------------------------
    // 14. Historical Quick Registration remains readable.
    // ------------------------------------------------------------------
    public function test_historical_pre_phase_c_transport_invoice_remains_readable(): void
    {
        $route = $this->route();
        // Simulate a pre-Phase-C invoice: Transport InvoiceItem metadata
        // exists, but no StudentTransportAssignment (that table/behavior
        // did not exist yet when this row was created).
        $student = Student::create(['name' => 'Историческая запись', 'status' => Student::STATUS_ACTIVE]);
        $enrollment = Enrollment::create(['student_id' => $student->id, 'academic_year_id' => $this->year->id, 'stage_id' => $this->stage->id, 'grade_id' => $this->grade->id, 'class_id' => $this->class->id, 'enrolled_at' => '2026-09-01', 'status' => 'active', 'is_active' => true]);
        $invoice = Invoice::create(['student_id' => $student->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'HIST-1', 'currency' => 'EGP', 'subtotal_amount' => '1500.00', 'total_amount' => '1500.00', 'paid_amount' => '0.00', 'remaining_amount' => '1500.00', 'status' => 'unpaid', 'due_date' => $this->year->end_date]);
        InvoiceItem::create(['invoice_id' => $invoice->id, 'fee_id' => $this->transportFee->id, 'description' => 'Трансфер', 'amount' => '1500.00', 'unit_price' => '1500.00', 'quantity' => 1, 'metadata' => ['area' => 'Зона 1', 'route_id' => $route->id, 'route' => $route->name]]);

        $this->assertSame(0, StudentTransportAssignment::count());
        $reloaded = Invoice::with('items')->findOrFail($invoice->id);
        $this->assertNotEmpty($reloaded->items);
        $this->assertSame('Зона 1', $reloaded->items->first()->metadata['area']);
    }
}
