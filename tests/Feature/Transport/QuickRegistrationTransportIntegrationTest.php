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
 * Transport Management Phase C — Quick Registration integration.
 *
 * When Quick Registration includes a Transport service, a canonical
 * StudentTransportAssignment is now created inside the SAME outer
 * transaction (App\Services\Admissions\QuickStudentRegistrationService::
 * register()) via the existing App\Services\Transport\
 * TransportAssignmentService — never a second, parallel write path, never
 * FeePrices/pricing/coverage/payment-allocation semantics changed.
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

    private function route(?string $pricingZone = 'Зона 1'): TransportRoute
    {
        return TransportRoute::create(['name' => 'Маршрут 1', 'pricing_zone' => $pricingZone, 'is_active' => true]);
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

    private function transportService(TransportRoute $route, Bus $bus, array $overrides = []): array
    {
        return array_replace([
            'fee_id' => $this->transportFee->id, 'quantity' => 1, 'paid_now' => '0.00',
            'transport_area' => 'Зона 1', 'transport_route_id' => $route->id, 'bus_id' => $bus->id,
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
    // 2. Transport QR creates exactly one StudentTransportAssignment.
    // 3. Correct Enrollment/route/bus/zone/pickup/effective date.
    // ------------------------------------------------------------------
    public function test_transport_registration_creates_exactly_one_correct_assignment(): void
    {
        $route = $this->route();
        $bus = $this->bus();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route, $bus)],
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, StudentTransportAssignment::count());
        $assignment = StudentTransportAssignment::sole();
        $enrollment = Enrollment::sole();
        $this->assertSame($enrollment->id, $assignment->enrollment_id);
        $this->assertSame($route->id, $assignment->transport_route_id);
        $this->assertSame($bus->id, $assignment->bus_id);
        $this->assertSame('Зона 1', $assignment->pricing_zone);
        $this->assertSame('У ворот', $assignment->pickup_point);
        $this->assertSame('2026-09-05', $assignment->effective_from->toDateString());
        $this->assertSame(StudentTransportAssignment::STATUS_ACTIVE, $assignment->status);
    }

    // ------------------------------------------------------------------
    // 4. Invoice amount unchanged. 5. FeePrice resolution remains zone-based.
    // ------------------------------------------------------------------
    public function test_invoice_amount_is_zone_priced_regardless_of_route_or_bus(): void
    {
        $routeA = $this->route();
        $busA = $this->bus();
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($routeA, $busA)],
        ]));
        $response->assertSessionHasNoErrors();
        $this->assertSame('1500.00', Invoice::sole()->total_amount);

        // A different route/bus, same zone -> identical price.
        $routeB = TransportRoute::create(['name' => 'Другой маршрут', 'pricing_zone' => null, 'is_active' => true]);
        $busB = $this->bus();
        $response2 = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'student_last_name_ru' => 'Петров', 'student_first_name_ru' => 'Пётр',
            'services' => [$this->transportService($routeB, $busB)],
        ]));
        $response2->assertSessionHasNoErrors();
        $this->assertSame(2, Invoice::count());
        $this->assertSame('1500.00', Invoice::latest('id')->first()->total_amount);
    }

    // ------------------------------------------------------------------
    // 6. Mixed billing unchanged.
    // ------------------------------------------------------------------
    public function test_mixed_billing_with_transport_and_registration_unchanged(): void
    {
        $registration = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1000.00', 'is_active' => true]);
        $route = $this->route();
        $bus = $this->bus();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [
                ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00'],
                $this->transportService($route, $bus),
            ],
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('2500.00', Invoice::sole()->total_amount);
        $this->assertSame(2, InvoiceItem::count());
        $this->assertSame(1, StudentTransportAssignment::count());
    }

    // ------------------------------------------------------------------
    // 7. Coverage/payment allocation unchanged.
    // ------------------------------------------------------------------
    public function test_transport_subscription_and_payment_still_created_as_before(): void
    {
        $route = $this->route();
        $bus = $this->bus();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route, $bus, ['paid_now' => '1500.00'])],
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, StudentServiceSubscription::count());
        $invoice = Invoice::sole();
        $this->assertSame('1500.00', $invoice->paid_amount);
        $this->assertSame(1, $invoice->payments()->count());
    }

    // ------------------------------------------------------------------
    // 8. 14th student succeeds. 9. 15th rejected. 10. Rollback on capacity failure.
    // ------------------------------------------------------------------
    public function test_fourteenth_succeeds_fifteenth_rejected_with_full_rollback(): void
    {
        $route = $this->route();
        $bus = $this->bus();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        foreach (range(1, 13) as $n) {
            $student = Student::create(['name' => "Filler {$n}", 'status' => Student::STATUS_ACTIVE]);
            $enrollment = Enrollment::create(['student_id' => $student->id, 'academic_year_id' => $this->year->id, 'stage_id' => $this->stage->id, 'grade_id' => $this->grade->id, 'class_id' => $this->class->id, 'enrolled_at' => '2026-09-01', 'status' => 'active', 'is_active' => true]);
            app(TransportAssignmentService::class)->assign($enrollment, $route, $bus, ['effective_from' => '2026-09-01'], $admin);
        }
        $this->assertSame(13, StudentTransportAssignment::where('bus_id', $bus->id)->count());

        // 14th — via the full Quick Registration HTTP flow.
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route, $bus)],
        ]));
        $response->assertSessionHasNoErrors();
        $this->assertSame(14, StudentTransportAssignment::where('bus_id', $bus->id)->count());
        $this->assertSame(1, Student::where('last_name_ru', 'Иванов')->count());

        // 15th — must be rejected, and the WHOLE registration rolled back.
        $studentCountBefore = Student::count();
        $invoiceCountBefore = Invoice::count();
        $response2 = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'student_last_name_ru' => 'Пятнадцатый', 'student_first_name_ru' => 'Ученик',
            'services' => [$this->transportService($route, $bus)],
        ]));
        $response2->assertStatus(500)->assertSee('14', false);
        $this->assertSame(14, StudentTransportAssignment::where('bus_id', $bus->id)->count());
        $this->assertSame($studentCountBefore, Student::count(), 'no orphan Student on capacity rollback');
        $this->assertSame($invoiceCountBefore, Invoice::count(), 'no orphan Invoice on capacity rollback');
    }

    // ------------------------------------------------------------------
    // 11. Inactive route rejected. 12. Inactive bus rejected.
    // ------------------------------------------------------------------
    public function test_inactive_route_is_rejected_and_rolls_back(): void
    {
        $route = TransportRoute::create(['name' => 'Неактивный', 'pricing_zone' => null, 'is_active' => false]);
        $bus = $this->bus();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route, $bus)],
        ]));

        // TransportAssignmentService throws ValidationException, which
        // Laravel's default handler converts into the SAME
        // redirect-back-with-session-errors response as every other
        // validation failure in this app — never a raw 500.
        $response->assertSessionHasErrors();
        $this->assertSame(0, Student::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, StudentTransportAssignment::count());
    }

    public function test_inactive_bus_is_rejected_and_rolls_back(): void
    {
        $route = $this->route();
        $bus = Bus::create(['vehicle_code' => uniqid('BUS-'), 'is_active' => false]);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route, $bus)],
        ]));

        $response->assertSessionHasErrors();
        $this->assertSame(0, Student::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, StudentTransportAssignment::count());
    }

    // ------------------------------------------------------------------
    // 13. route/zone mismatch rejected. 14. nullable route zone + explicit valid zone succeeds.
    // ------------------------------------------------------------------
    public function test_route_zone_mismatch_is_rejected_by_validation(): void
    {
        $route = $this->route('Зона 2'); // route belongs to a different zone than submitted
        $bus = $this->bus();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route, $bus, ['transport_area' => 'Зона 1'])],
        ]));

        $response->assertSessionHasErrors(['services.0.transport_route_id']);
        $this->assertSame(0, Student::count());
        $this->assertSame(0, StudentTransportAssignment::count());
    }

    public function test_nullable_route_zone_with_explicit_valid_zone_succeeds(): void
    {
        $route = $this->route(null);
        $bus = $this->bus();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route, $bus, ['transport_area' => 'Зона 1'])],
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Зона 1', StudentTransportAssignment::sole()->pricing_zone);
    }

    // ------------------------------------------------------------------
    // 15. Retry does not duplicate assignment.
    // ------------------------------------------------------------------
    public function test_retry_with_same_idempotency_token_does_not_duplicate_assignment(): void
    {
        $route = $this->route();
        $bus = $this->bus();
        $token = 'retry-token-'.uniqid();

        $payload = $this->payload([
            'idempotency_token' => $token,
            'services' => [$this->transportService($route, $bus)],
        ]);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, Student::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, StudentTransportAssignment::count());
    }

    // ------------------------------------------------------------------
    // 16. Missing Transport permission rejected server-side.
    // ------------------------------------------------------------------
    public function test_missing_transport_permission_is_rejected_server_side(): void
    {
        // 'cashier' has portal access + 'manage invoices' (passes the
        // FormRequest gate) but was never granted
        // TransportPermissions::MANAGE_ASSIGNMENTS.
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole('cashier');
        $this->assertTrue($operator->can('manage invoices'));
        $this->assertFalse($operator->can(TransportPermissions::MANAGE_ASSIGNMENTS));
        $route = $this->route();
        $bus = $this->bus();

        $response = $this->actingAs($operator)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route, $bus)],
        ]));

        $response->assertForbidden();
        $this->assertSame(0, Student::count());
        $this->assertSame(0, StudentTransportAssignment::count());
    }

    // ------------------------------------------------------------------
    // 17. No legacy table writes.
    // ------------------------------------------------------------------
    public function test_no_legacy_transport_table_writes(): void
    {
        $route = $this->route();
        $bus = $this->bus();

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            'services' => [$this->transportService($route, $bus)],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('transport_subscriptions')->count());
        $this->assertSame(0, DB::table('bus_student')->count());
        $this->assertSame(0, DB::table('bus_stops')->count());
    }

    // ------------------------------------------------------------------
    // 18. Historical Quick Registration remains readable.
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
