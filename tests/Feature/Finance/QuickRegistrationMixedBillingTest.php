<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicCalendar;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\MealPlan;
use App\Models\SchoolClass;
use App\Models\ServiceCoverage;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use App\Services\Finance\InvoiceIssuanceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Finance V2 Phase 1 — Quick Registration per-service billing strategy.
 *
 * Root cause this pass fixes: today, ONE invoice forces ONE payment_type
 * (one_time/calendar/plan) and, for 'calendar', ONE shared billing_period
 * across every non-Food Fee — Registration/Uniform (once-only by nature)
 * could not coexist with Tuition (monthly) in the same invoice at all
 * unless every Fee happened to share the identical calendar period.
 *
 * Introduces a new payment_type='mixed', under which each service resolves
 * its OWN strategy ('once' or 'calendar' + its own billing_period),
 * validated server-side against that Fee's own allowsBillingPeriod() —
 * never trusted from the client. Food is untouched (still resolves via its
 * own duration-mode fields, independent of billing_strategy entirely).
 * Custom PaymentPlan per service is explicitly OUT of scope (Phase 2) —
 * payment_type='plan' and 'mixed' remain mutually exclusive.
 *
 * payment_type is a closed enum, so every existing value ('one_time',
 * 'calendar', 'plan') — and therefore every existing caller, including the
 * classic invoice screen and mass billing, neither of which ever sends
 * 'mixed' — is completely untouched by this addition.
 */
class QuickRegistrationMixedBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;
    private AcademicYear $year;
    private array $base;
    private CashAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');

        // Deliberately chosen so monthly billing from the registration date
        // (year start) produces exactly 9 whole calendar-month periods, and
        // quarterly produces exactly 3 whole 3-month periods (9 = 3x3, no
        // trailing partial group) — clean, easily-verified numbers.
        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-04-30', 'is_active' => true]);
        $stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $stage->id, 'level' => 1]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'А', 'name_ru' => 'А', 'name_ar' => 'A', 'is_active' => true]);
        $mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очная форма', 'is_active' => true]);

        $this->base = [
            'student_last_name_ru' => 'Иванова', 'student_first_name_ru' => 'Анна',
            'phone' => '+20 100 555 7788', 'registration_date' => '2026-08-01',
            'academic_year_id' => $this->year->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id,
            'class_id' => $class->id, 'enrollment_mode_id' => $mode->id,
            'payment_type' => 'mixed',
        ];

        $this->account = CashAccount::operating();
    }

    private function payload(array $services, array $overrides = []): array
    {
        return array_replace($this->base + ['services' => $services], $overrides);
    }

    private function registrationFee(string $amount = '7000.00'): Fee
    {
        return Fee::create(['name_ru' => 'Организационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => $amount, 'is_active' => true]);
    }

    private function uniformFee(): Fee
    {
        return Fee::create(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '0.00', 'is_active' => true]);
    }

    private function sellableUniformItem(Fee $fee, string $item, string $size, string $amount): int
    {
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'item' => $item, 'size' => $size,
        ]);

        return DB::table('uniform_products')->insertGetId([
            'name_ru' => $item, 'category' => 'garment', 'size' => $size, 'price' => $amount,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Tuition, configured to allow monthly billing, priced at $monthlyUnit/month (x9 over this fixture's year = the total). */
    private function tuitionFee(string $monthlyUnit = '2000.00'): Fee
    {
        $fee = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        // Dimensioned by grade_group (this system's real Tuition
        // convention), never a raw grade_id — every submitted Tuition
        // service line below supplies the SAME grade_group explicitly so
        // QuickStudentRegistrationService never falls back to assigning
        // the enrolling student's raw grade_id (which this FeePrice
        // deliberately doesn't carry, exactly like a real grade_group-
        // dimensioned Tuition tariff never does).
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $monthlyUnit, 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'payment_period' => 'monthly', 'grade_group' => '1–4 классы',
        ]);

        return $fee;
    }

    /** Transport, configured to allow quarterly billing, priced at $quarterlyUnit/quarter (x3 over this fixture's year = the total). @return array{Fee, int} [fee, transport_route_id] */
    private function transportFee(string $quarterlyUnit = '1500.00'): array
    {
        $fee = Fee::create(['name_ru' => 'Трансфер', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '0.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'quarterly']);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $quarterlyUnit, 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'quarterly',
        ]);
        // createAutomaticCoverage() always establishes MONTHLY-unit
        // coverage for a non-Food calendar Fee (billing_unit is
        // hard-coded to 'monthly' there, regardless of the invoice's own
        // billing_period) — an existing, unchanged requirement this phase
        // does not alter: a quarterly-billed Fee still needs its own
        // monthly tariff configured as the coverage/tariff-adjustment
        // basis price. Never used for the actual quarterly charge amount.
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => bcdiv($quarterlyUnit, '3', 2), 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly',
        ]);
        $routeId = DB::table('transport_routes')->insertGetId(['name' => 'Маршрут 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return [$fee, $routeId];
    }

    /** Food, priced daily, existing independent duration-mode path. @return array{Fee, MealPlan} */
    private function foodFee(string $dailyAmount = '85.00'): array
    {
        AcademicCalendar::create(['academic_year_id' => $this->year->id, 'weekly_days_off' => ['fri', 'sat']]);
        $fee = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '0.00', 'is_active' => true]);
        $plan = MealPlan::create(['name_ru' => 'Комплексное питание', 'meal_type' => MealPlan::TYPE_BOTH, 'period' => MealPlan::PERIOD_DAILY, 'price' => $dailyAmount, 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $dailyAmount, 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => (string) $plan->id, 'payment_period' => 'daily',
        ]);

        return [$fee, $plan];
    }

    private function openCashSession(): void
    {
        app(CashSessionService::class)->open($this->account, $this->accountant);
    }

    // ------------------------------------------------------------------
    // A. Mixed invoice: Registration once + Tuition monthly + Uniform once.
    // ------------------------------------------------------------------
    public function test_mixed_invoice_registration_tuition_monthly_uniform(): void
    {
        $registration = $this->registrationFee('7000.00');
        $tuition = $this->tuitionFee('2000.00');
        $uniform = $this->uniformFee();
        $productId = $this->sellableUniformItem($uniform, 'Майка', '14', '500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00'],
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
            ['fee_id' => $uniform->id, 'paid_now' => '0.00', 'uniform_items' => [['uniform_product_id' => $productId, 'quantity' => 1]]],
        ]))->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        // 7000 + (2000 x 9) + 500 = 25500.
        $this->assertSame('25500.00', $invoice->total_amount);
        $this->assertDatabaseCount('invoice_items', 3);

        $installments = $invoice->installments()->orderBy('sequence')->get();
        $this->assertCount(10, $installments, 'expected 1 once installment + 9 monthly Tuition installments');
        $this->assertSame($installments->pluck('sequence')->all(), range(1, 10), 'installment sequence must be unique and contiguous, no collisions');

        $once = $installments->firstWhere('name_ru', InvoiceIssuanceService::MIXED_ONCE_INSTALLMENT_NAME);
        $this->assertNotNull($once, 'the once-strategy lump-sum installment must exist');
        $this->assertSame('7500.00', $once->amount, 'once installment = Registration(7000) + Uniform(500) — never Tuition');

        $monthly = $installments->reject(fn (InvoiceInstallment $i) => $i->id === $once->id);
        $this->assertCount(9, $monthly);
        $this->assertTrue($monthly->every(fn (InvoiceInstallment $i) => $i->amount === '2000.00'), 'each monthly installment = Tuition\'s own per-month share only');
    }

    // ------------------------------------------------------------------
    // B. Add Transport quarterly — independent monthly vs quarterly
    // schedules, each summing only its own group.
    // ------------------------------------------------------------------
    public function test_mixed_invoice_with_independent_monthly_and_quarterly_groups(): void
    {
        $registration = $this->registrationFee('7000.00');
        $tuition = $this->tuitionFee('2000.00');
        [$transport, $routeId] = $this->transportFee('1500.00');
        $uniform = $this->uniformFee();
        $productId = $this->sellableUniformItem($uniform, 'Майка', '14', '500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00'],
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
            ['fee_id' => $transport->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'quarterly', 'transport_area' => 'Зона 1', 'transport_route_id' => $routeId, 'bus_id' => \App\Models\Bus::create(['vehicle_code' => uniqid('BUS-'), 'is_active' => true])->id],
            ['fee_id' => $uniform->id, 'paid_now' => '0.00', 'uniform_items' => [['uniform_product_id' => $productId, 'quantity' => 1]]],
        ]))->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        // 7000 + (2000x9=18000) + (1500x3=4500) + 500 = 30000.
        $this->assertSame('30000.00', $invoice->total_amount);
        $this->assertDatabaseCount('invoice_items', 4);

        $installments = $invoice->installments()->orderBy('sequence')->get();
        // 1 once + 9 monthly + 3 quarterly = 13, contiguous, no collisions.
        $this->assertCount(13, $installments);
        $this->assertSame(range(1, 13), $installments->pluck('sequence')->all());

        $once = $installments->firstWhere('name_ru', InvoiceIssuanceService::MIXED_ONCE_INSTALLMENT_NAME);
        $this->assertSame('7500.00', $once->amount);

        // Tuition's monthly items belong ONLY to the InvoiceItem the
        // tuition Fee produced — every monthly installment's own amount
        // must equal exactly Tuition's per-month share, never contaminated
        // by Transport's quarterly amount or vice versa.
        $rest = $installments->reject(fn (InvoiceInstallment $i) => $i->id === $once->id);
        $monthlyAmounts = $rest->filter(fn (InvoiceInstallment $i) => $i->amount === '2000.00');
        $quarterlyAmounts = $rest->filter(fn (InvoiceInstallment $i) => $i->amount === '1500.00');
        $this->assertCount(9, $monthlyAmounts, 'exactly 9 installments carrying only Tuition\'s own monthly amount');
        $this->assertCount(3, $quarterlyAmounts, 'exactly 3 installments carrying only Transport\'s own quarterly amount');
        // No installment's amount is a sum/blend of both groups.
        $this->assertTrue($rest->every(fn (InvoiceInstallment $i) => in_array($i->amount, ['2000.00', '1500.00'], true)));

        // Coverage: createAutomaticCoverage() (completely unchanged,
        // reused verbatim per group) always establishes MONTHLY-unit
        // ServiceCoverage for every non-Food calendar Fee regardless of
        // its own actual billing_period — an existing, documented
        // behavior this phase does not alter or invent around. Both
        // Tuition (billed monthly) and Transport (billed quarterly, but
        // configured with its own monthly basis tariff — see
        // transportFee()) get exactly one coverage row each, both
        // billing_unit='monthly'.
        $tuitionItem = InvoiceItem::where('fee_id', $tuition->id)->sole();
        $transportItem = InvoiceItem::where('fee_id', $transport->id)->sole();
        $this->assertSame(1, ServiceCoverage::where('invoice_item_id', $tuitionItem->id)->count(), 'Tuition (monthly) must have coverage');
        $this->assertSame(1, ServiceCoverage::where('invoice_item_id', $transportItem->id)->count(), 'Transport (quarterly) still gets monthly-unit coverage — same existing rule createAutomaticCoverage() already applies to single-strategy calendar invoices');
        $this->assertSame('monthly', ServiceCoverage::where('invoice_item_id', $transportItem->id)->sole()->billing_unit);
    }

    // ------------------------------------------------------------------
    // C. Add Food custom coverage — unchanged behavior, correctly
    // appended without sequence collision.
    // ------------------------------------------------------------------
    public function test_mixed_invoice_food_remains_unchanged_and_sequence_never_collides(): void
    {
        $registration = $this->registrationFee('7000.00');
        $tuition = $this->tuitionFee('2000.00');
        [$food, $plan] = $this->foodFee('85.00');
        $uniform = $this->uniformFee();
        $productId = $this->sellableUniformItem($uniform, 'Майка', '14', '500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00'],
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
            ['fee_id' => $food->id, 'quantity' => 1, 'paid_now' => '0.00', 'meal_plan_id' => $plan->id, 'food_duration_mode' => 'day', 'food_date' => '2026-08-03'],
            ['fee_id' => $uniform->id, 'paid_now' => '0.00', 'uniform_items' => [['uniform_product_id' => $productId, 'quantity' => 1]]],
        ]))->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $foodItem = InvoiceItem::where('fee_id', $food->id)->sole();
        $this->assertSame('85.00', $foodItem->amount, 'Food priced correctly — one billable day at its own daily tariff');
        $this->assertSame(1, ServiceCoverage::where('invoice_item_id', $foodItem->id)->count(), 'Food coverage created exactly as it already is today');

        $installments = $invoice->installments()->orderBy('sequence')->get();
        // 1 once + 9 monthly + 1 Food lump sum = 11, contiguous, no collisions.
        $this->assertCount(11, $installments);
        $this->assertSame(range(1, 11), $installments->pluck('sequence')->all(), 'Food\'s own installment must append after the mixed groups without colliding');
        $foodInstallment = $installments->firstWhere('name_ru', 'Питание');
        $this->assertNotNull($foodInstallment);
        $this->assertSame('85.00', $foodInstallment->amount);
    }

    // ------------------------------------------------------------------
    // D. Payment allocation across Registration + Tuition + Transport +
    // Uniform + Food — persisted per-item allocations sum to paid_now.
    // ------------------------------------------------------------------
    public function test_payment_allocation_across_all_five_categories(): void
    {
        $this->openCashSession();
        $registration = $this->registrationFee('7000.00');
        $tuition = $this->tuitionFee('2000.00');
        [$transport, $routeId] = $this->transportFee('1500.00');
        [$food, $plan] = $this->foodFee('85.00');
        $uniform = $this->uniformFee();
        $productId = $this->sellableUniformItem($uniform, 'Майка', '14', '500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
            ['fee_id' => $transport->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'quarterly', 'transport_area' => 'Зона 1', 'transport_route_id' => $routeId, 'bus_id' => \App\Models\Bus::create(['vehicle_code' => uniqid('BUS-'), 'is_active' => true])->id],
            ['fee_id' => $food->id, 'quantity' => 1, 'paid_now' => '85.00', 'meal_plan_id' => $plan->id, 'food_duration_mode' => 'day', 'food_date' => '2026-08-03'],
            ['fee_id' => $uniform->id, 'paid_now' => '500.00', 'uniform_items' => [['uniform_product_id' => $productId, 'quantity' => 1]]],
        ], ['payment_method' => 'cash', 'cash_account_id' => $this->account->id]))->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        // paid_now sums: 7000 (Registration, once) + 0 (Tuition) + 0 (Transport) + 85 (Food) + 500 (Uniform, once) = 7585.
        $paidNow = '7585.00';

        $registrationItem = InvoiceItem::where('fee_id', $registration->id)->sole();
        $tuitionItem = InvoiceItem::where('fee_id', $tuition->id)->sole();
        $transportItem = InvoiceItem::where('fee_id', $transport->id)->sole();
        $foodItem = InvoiceItem::where('fee_id', $food->id)->sole();
        $uniformItem = InvoiceItem::where('fee_id', $uniform->id)->sole();

        $this->assertSame('7000.00', $registrationItem->paid_amount);
        $this->assertSame('500.00', $uniformItem->paid_amount);
        $this->assertSame('85.00', $foodItem->paid_amount);
        $this->assertSame('0.00', $tuitionItem->paid_amount);
        $this->assertSame('0.00', $transportItem->paid_amount);

        // No item ever receives more than its own canonical amount.
        foreach ([$registrationItem, $tuitionItem, $transportItem, $foodItem, $uniformItem] as $item) {
            $this->assertLessThanOrEqual(0, bccomp($item->paid_amount, $item->amount, 2));
        }

        // Auditable: persisted payment_allocations sum exactly to paid_now.
        $allocatedTotal = DB::table('payment_allocations')
            ->join('invoice_payments', 'invoice_payments.id', '=', 'payment_allocations.invoice_payment_id')
            ->where('invoice_payments.invoice_id', $invoice->id)
            ->sum('payment_allocations.amount');
        $this->assertSame(0, bccomp((string) $allocatedTotal, $paidNow, 2), 'persisted per-item allocations must sum exactly to paid_now');
    }

    // ------------------------------------------------------------------
    // E. Atomic failure: one invalid billing period among otherwise-valid
    // services → zero partial side effects.
    // ------------------------------------------------------------------
    public function test_atomic_failure_on_invalid_billing_period_leaves_no_side_effects(): void
    {
        $registration = $this->registrationFee('7000.00');
        $tuition = $this->tuitionFee('2000.00');
        $uniform = $this->uniformFee();
        $productId = $this->sellableUniformItem($uniform, 'Майка', '14', '500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00'],
            // Tuition is only configured for 'monthly' — requesting
            // 'quarterly' for it must be rejected.
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'quarterly', 'grade_group' => '1–4 классы'],
            ['fee_id' => $uniform->id, 'paid_now' => '0.00', 'uniform_items' => [['uniform_product_id' => $productId, 'quantity' => 1]]],
        ]))->assertSessionHasErrors();

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('invoice_installments', 0);
        $this->assertDatabaseCount('service_coverages', 0);
        $this->assertDatabaseCount('invoice_payments', 0);
    }

    // ------------------------------------------------------------------
    // F. Unsupported Fee period rejected per-service (once-only Fee
    // requesting a calendar strategy).
    // ------------------------------------------------------------------
    public function test_once_only_fee_cannot_be_forced_into_calendar_strategy(): void
    {
        $registration = $this->registrationFee('7000.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly'],
        ]))->assertSessionHasErrors('services.0.billing_strategy');

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    // ------------------------------------------------------------------
    // M. PaymentPlan mixed-strategy request fails closed.
    // ------------------------------------------------------------------
    public function test_payment_plan_id_with_mixed_payment_type_fails_closed(): void
    {
        $registration = $this->registrationFee('7000.00');
        $plan = \App\Models\PaymentPlan::create(['name_ru' => 'План', 'is_active' => true]);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00'],
        ], ['payment_plan_id' => $plan->id]))->assertSessionHasErrors('payment_plan_id');

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    // ------------------------------------------------------------------
    // billing_strategy is meaningless (and rejected) outside payment_type=mixed.
    // ------------------------------------------------------------------
    public function test_billing_strategy_rejected_outside_mixed_payment_type(): void
    {
        $tuition = $this->tuitionFee('2000.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
        ], ['payment_type' => 'one_time']))->assertSessionHasErrors('services.0.billing_strategy');
    }

    // ------------------------------------------------------------------
    // N. Single-strategy Quick Registration remains backward-compatible
    // (payment_type=calendar, unrelated to 'mixed' entirely).
    // ------------------------------------------------------------------
    public function test_existing_single_strategy_calendar_flow_unaffected(): void
    {
        $tuition = $this->tuitionFee('2000.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
        ], ['payment_type' => 'calendar', 'billing_period' => 'monthly']))->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame('18000.00', $invoice->total_amount);
        $this->assertSame(9, $invoice->installments()->count());
    }

    // ------------------------------------------------------------------
    // O. Retry/idempotency across the new two-InvoicePayment mixed
    // settlement path (once bucket + calendar bucket, both non-zero).
    // ------------------------------------------------------------------
    public function test_retry_with_same_idempotency_token_does_not_duplicate_the_two_payment_mixed_settlement(): void
    {
        $this->openCashSession();
        $registration = $this->registrationFee('7000.00');
        $tuition = $this->tuitionFee('2000.00');
        $uniform = $this->uniformFee();
        $productId = $this->sellableUniformItem($uniform, 'Майка', '14', '500.00');

        $payload = $this->payload([
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '1200.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
            ['fee_id' => $uniform->id, 'paid_now' => '500.00', 'uniform_items' => [['uniform_product_id' => $productId, 'quantity' => 1]]],
        ], [
            'payment_method' => 'cash', 'cash_account_id' => $this->account->id,
            'idempotency_token' => 'test-mixed-retry-token-0001',
        ]);

        $first = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload);
        $first->assertSessionHasNoErrors()->assertRedirect();

        $invoice = Invoice::sole();
        // Sanity: once bucket (Registration 7000 + Uniform 500 = 7500,
        // fully settling the once installment) AND periods bucket
        // (Tuition's 1200 partial payment) are both non-zero — this
        // scenario must actually exercise BOTH InvoicePayment rows the
        // mixed-settlement path can create, not just one.
        $this->assertSame(2, InvoicePayment::where('invoice_id', $invoice->id)->count(), 'sanity: scenario must exercise both mixed-settlement payment buckets');

        $studentCountBefore = Student::count();
        $itemCountBefore = InvoiceItem::count();
        $installmentCountBefore = InvoiceInstallment::count();
        $coverageCountBefore = ServiceCoverage::count();
        $paymentCountBefore = InvoicePayment::count();
        $allocationSumBefore = (string) DB::table('payment_allocations')->sum('amount');
        $invoice->refresh();
        $totalAmountBefore = $invoice->total_amount;
        $paidAmountBefore = $invoice->paid_amount;

        $second = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload);
        $second->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($studentCountBefore, Student::count(), 'retry must not create a second student');
        $this->assertSame(1, Invoice::count(), 'retry must not create a second invoice');
        $this->assertSame($itemCountBefore, InvoiceItem::count(), 'retry must not duplicate invoice items');
        $this->assertSame($installmentCountBefore, InvoiceInstallment::count(), 'retry must not duplicate installments');
        $this->assertSame($coverageCountBefore, ServiceCoverage::count(), 'retry must not duplicate coverage rows');
        $this->assertSame($paymentCountBefore, InvoicePayment::count(), 'retry must not duplicate either of the two mixed-settlement payment rows');
        $this->assertSame(0, bccomp((string) DB::table('payment_allocations')->sum('amount'), $allocationSumBefore, 2), 'retry must not duplicate payment allocations');

        $invoice->refresh();
        $this->assertSame($totalAmountBefore, $invoice->total_amount, 'total amount must be unchanged after retry');
        $this->assertSame($paidAmountBefore, $invoice->paid_amount, 'paid amount must be unchanged after retry');
    }

    // ------------------------------------------------------------------
    // P. Yearly mixed group — once-group and yearly-group installments
    // never blend, unique sequence numbers, payment allocation still
    // valid.
    // ------------------------------------------------------------------
    public function test_yearly_mixed_group_installment_uses_only_its_own_group_amount(): void
    {
        $this->openCashSession();
        $registration = $this->registrationFee('7000.00');
        $tuition = Fee::create(['name_ru' => 'Обучение (год)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => true]);
        $tuition->billingPeriods()->create(['billing_period' => 'yearly']);
        // Yearly billing derives its ServiceCoverage basis from a MONTHLY
        // tariff (createAutomaticCoverage() always uses billing_unit=
        // 'monthly') — an existing, unchanged requirement, not something
        // Phase 1 invents; both this monthly basis and the explicit
        // yearly charge amount are configured, same as a real yearly
        // Tuition tariff would be.
        FeePrice::create([
            'fee_id' => $tuition->id, 'academic_year_id' => $this->year->id, 'amount' => '1500.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'payment_period' => 'monthly', 'grade_group' => '1–4 классы',
        ]);
        FeePrice::create([
            'fee_id' => $tuition->id, 'academic_year_id' => $this->year->id, 'amount' => '15000.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'payment_period' => 'yearly', 'grade_group' => '1–4 классы',
        ]);
        $uniform = $this->uniformFee();
        $productId = $this->sellableUniformItem($uniform, 'Майка', '14', '500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '10000.00', 'billing_strategy' => 'calendar', 'payment_period' => 'yearly', 'grade_group' => '1–4 классы'],
            ['fee_id' => $uniform->id, 'paid_now' => '500.00', 'uniform_items' => [['uniform_product_id' => $productId, 'quantity' => 1]]],
        ], ['payment_method' => 'cash', 'cash_account_id' => $this->account->id]))->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        // 7000 (Registration) + 15000 (Tuition yearly) + 500 (Uniform) = 22500.
        $this->assertSame('22500.00', $invoice->total_amount);

        $installments = $invoice->installments()->orderBy('sequence')->get();
        $this->assertCount(2, $installments, 'exactly 1 once installment + 1 yearly installment, no blending');
        $this->assertSame([1, 2], $installments->pluck('sequence')->all(), 'unique, contiguous sequence numbers, no collision');

        $once = $installments->firstWhere('name_ru', InvoiceIssuanceService::MIXED_ONCE_INSTALLMENT_NAME);
        $this->assertNotNull($once);
        $this->assertSame('7500.00', $once->amount, 'once installment = Registration(7000) + Uniform(500) only — never the yearly Tuition amount');

        $yearly = $installments->reject(fn (InvoiceInstallment $i) => $i->id === $once->id)->sole();
        $this->assertSame('15000.00', $yearly->amount, "yearly installment = Tuition's own group total only — never blended with the once group");

        // paid_now sums 7000+10000+500=17500. The once bucket (7500) is
        // fully settled; the remaining 10000 goes to the yearly
        // installment's own coverage-period allocation — still valid,
        // never exceeding the item's own canonical amount.
        $tuitionItem = InvoiceItem::where('fee_id', $tuition->id)->sole();
        $this->assertSame('10000.00', $tuitionItem->paid_amount);
        $this->assertLessThanOrEqual(0, bccomp($tuitionItem->paid_amount, $tuitionItem->amount, 2));

        $this->assertSame(1, ServiceCoverage::where('invoice_item_id', $tuitionItem->id)->count(), 'yearly Tuition still gets monthly-unit coverage — same pre-existing rule createAutomaticCoverage() already applies');
        $this->assertSame('monthly', ServiceCoverage::where('invoice_item_id', $tuitionItem->id)->sole()->billing_unit);
    }

    // ------------------------------------------------------------------
    // Q. Partial payment crossing from the once bucket into a calendar
    // bucket — explicitly documents/protects the two-InvoicePayment-rows
    // behavior the independent review flagged as high-risk.
    // ------------------------------------------------------------------
    public function test_partial_payment_crosses_from_once_bucket_into_calendar_bucket(): void
    {
        $this->openCashSession();
        $registration = $this->registrationFee('7000.00');
        $tuition = $this->tuitionFee('2000.00');

        // once group total = 7000 (paid in full); calendar (monthly
        // Tuition) first installment = 2000, paid only 1200 of it —
        // paid_now crosses from fully settling the once group into
        // partially settling ONLY the first calendar installment.
        $paidNow = '8200.00';
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '1200.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
        ], ['payment_method' => 'cash', 'cash_account_id' => $this->account->id]))->assertSessionHasNoErrors();

        $invoice = Invoice::sole()->fresh();
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);
        $this->assertSame($paidNow, $invoice->paid_amount);
        // total = 7000 + (2000x9=18000) = 25000; remaining = 25000-8200=16800.
        $this->assertSame('25000.00', $invoice->total_amount);
        $this->assertSame('16800.00', $invoice->remaining_amount);

        $installments = $invoice->installments()->orderBy('sequence')->get();
        $once = $installments->firstWhere('name_ru', InvoiceIssuanceService::MIXED_ONCE_INSTALLMENT_NAME);
        $this->assertNotNull($once);
        $this->assertSame('0.00', $once->remaining_amount, 'the once installment must be fully settled first');

        $monthly = $installments->reject(fn (InvoiceInstallment $i) => $i->id === $once->id)->sortBy('sequence')->values();
        $this->assertCount(9, $monthly);
        $this->assertSame('800.00', $monthly->first()->remaining_amount, 'first monthly installment partially settled: 2000 - 1200 = 800 remaining');
        $this->assertTrue($monthly->slice(1)->every(fn (InvoiceInstallment $i) => $i->remaining_amount === $i->amount), 'every later monthly installment must be completely untouched');

        $registrationItem = InvoiceItem::where('fee_id', $registration->id)->sole();
        $tuitionItem = InvoiceItem::where('fee_id', $tuition->id)->sole();
        $this->assertSame('7000.00', $registrationItem->paid_amount);
        $this->assertSame('1200.00', $tuitionItem->paid_amount);
        // No item ever receives more than its own canonical amount.
        foreach ([$registrationItem, $tuitionItem] as $item) {
            $this->assertLessThanOrEqual(0, bccomp($item->paid_amount, $item->amount, 2));
        }

        // Auditable: persisted per-item allocations sum exactly to paid_now.
        $allocatedTotal = DB::table('payment_allocations')
            ->join('invoice_payments', 'invoice_payments.id', '=', 'payment_allocations.invoice_payment_id')
            ->where('invoice_payments.invoice_id', $invoice->id)
            ->sum('payment_allocations.amount');
        $this->assertSame(0, bccomp((string) $allocatedTotal, $paidNow, 2), 'persisted per-item allocations must sum exactly to paid_now');

        // Explicitly document/protect the two-InvoicePayment-rows design:
        // crossing from the once bucket into a calendar bucket in ONE
        // operator payment action creates exactly two InvoicePayment
        // rows (one per bucket) — mathematically correct and auditable,
        // but a real accounting/receipt-clarity characteristic any
        // caller or receipt view must be aware of.
        $this->assertSame(2, InvoicePayment::where('invoice_id', $invoice->id)->count(), 'crossing once+calendar buckets in one operator payment creates two InvoicePayment rows by design — protect/document this explicitly');
    }

    // ------------------------------------------------------------------
    // R. Uniform procurement report is unaffected by mixed billing — the
    // report reads InvoiceItem.metadata['item']/['size'] directly and has
    // no knowledge of payment_type at all.
    // ------------------------------------------------------------------
    public function test_mixed_invoice_uniform_procurement_report_unaffected_by_mixed_billing(): void
    {
        $tuition = $this->tuitionFee('2000.00');
        $uniform = $this->uniformFee();
        $productId = $this->sellableUniformItem($uniform, 'Майка', '14', '500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
            ['fee_id' => $uniform->id, 'paid_now' => '0.00', 'uniform_items' => [['uniform_product_id' => $productId, 'quantity' => 2]]],
        ]))->assertSessionHasNoErrors();

        $invoiceItem = InvoiceItem::where('fee_id', $uniform->id)->sole();
        $this->assertSame('Майка', $invoiceItem->metadata['item']);
        $this->assertSame('14', $invoiceItem->metadata['size']);
        $this->assertSame(2, $invoiceItem->quantity);

        $exitCode = Artisan::call('finance:uniform-procurement-report', ['--year' => $this->year->name]);
        $output = Artisan::output();
        $this->assertSame(0, $exitCode);

        $rows = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            if (count($cells) < 3 || $cells[0] === 'item') {
                continue;
            }
            $rows[$cells[0].'|'.$cells[1]] = $cells[2];
        }

        $this->assertSame('2', $rows['Майка|14'] ?? null, "expected exact-size row missing or wrong quantity in report output:\n{$output}");
        $this->assertArrayNotHasKey('Майка|6–10', $rows, 'mixed billing must never produce a legacy grouped-size row');
        $this->assertCount(1, $rows, "mixed billing must not fragment or duplicate the procurement report's per-size aggregation:\n{$output}");
    }
}
