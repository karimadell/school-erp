<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicCalendar;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\InstallmentCoveragePeriod;
use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\InvoiceItem;
use App\Models\MealPlan;
use App\Models\PaymentAllocation;
use App\Models\SchoolClass;
use App\Models\ServiceCoverage;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Quick Registration end-to-end performance remediation.
 *
 * UAT evidence (QR_TRACE rounds 1-3, PRs #43/#45/#46) proved the Quick
 * Registration 504 was caused by many sequential, individually-cheap DB
 * round trips compounding under a real network-latency-bound PostgreSQL
 * connection — invisible on this suite's latency-free SQLite connection,
 * which is exactly why query COUNT (not wall-clock time on this suite) is
 * the metric this test protects. Fixes in this pass:
 *
 *  - FinanceConfigurationReadinessService::forFees() no longer re-runs
 *    $year->academicCalendar()->exists() once per Fee (a genuine N+1 —
 *    confirmed scaling +1 query per extra Fee row) and can now reuse a
 *    caller's already-loaded MealPlan/uniform_products rows instead of
 *    re-querying them.
 *  - QuickStudentRegistrationController::create() (the GET page) fetches
 *    meal plans/uniform products/payment plans ONCE and shares them with
 *    the readiness computation, instead of querying each table twice.
 *  - Fee::allowsBillingPeriod()/allowedBillingPeriods() now cache a
 *    fallback query's own result onto the instance, and the 3 places Fee
 *    rows are batch-fetched in the Quick Registration path (request
 *    validation, QuickStudentRegistrationService, InvoiceIssuanceService)
 *    all eager-load billingPeriods — together eliminating what was 14
 *    separate fee_billing_periods queries for a 5-service registration.
 *  - QuickStudentRegistrationService::register() batch-fetches every
 *    submitted Fee once instead of one Fee::findOrFail() per service line.
 *  - StudentServiceSubscription::resolveAcademicYear() (invoked by
 *    AcademicYearLockObserver on every subscription create) answers with
 *    one subquery instead of two sequential fresh queries — the "always
 *    fresh, never a stale cached relation" guarantee is unchanged, only
 *    the round-trip count to get that same fresh answer.
 *  - StudentServiceSubscriptionService memoizes FinancePolicySetting::
 *    current() per instance instead of re-querying it once per service
 *    line.
 *  - InvoiceIssuanceService pre-sets the invoice/fee relations on each
 *    InvoiceItem before calling ServiceCoverageService::record()/
 *    recordWithBasisPrice(), so their own loadMissing() skips two of its
 *    four relation loads for objects already in hand.
 *  - InstallmentPlanService::generateCalendarSchedule() bulk-inserts every
 *    installment in one statement (InvoiceInstallment has no model event,
 *    so — unlike the InstallmentCoveragePeriod fix in PR #45 — no per-row
 *    validation is bypassed at all) instead of one InvoiceInstallment::
 *    create() per period, then re-fetches them once, ordered, as real
 *    Eloquent models.
 *
 * None of this changes what gets persisted — every assertion below on
 * totals/balances/installments/coverage/allocations must still hold
 * exactly as before these fixes.
 */
class QuickRegistrationEndToEndPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private AcademicYear $year;

    private array $base;

    private CashAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');
        $this->accountant->givePermissionTo('manage invoices');

        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-04-30', 'is_active' => true]);
        $this->stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $this->grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $this->stage->id, 'level' => 1]);
        $this->class = SchoolClass::create(['grade_id' => $this->grade->id, 'code' => 'А', 'name_ru' => 'А', 'name_ar' => 'A', 'is_active' => true]);
        $this->mode = EnrollmentMode::create(['code' => EnrollmentMode::FULL_TIME, 'name_ru' => 'Очная форма', 'is_active' => true]);

        $this->base = [
            'student_last_name_ru' => 'Петрова', 'student_first_name_ru' => 'Мария',
            'phone' => '+20 100 555 9911', 'registration_date' => '2026-08-01',
            'academic_year_id' => $this->year->id, 'stage_id' => $this->stage->id, 'grade_id' => $this->grade->id,
            'class_id' => $this->class->id, 'enrollment_mode_id' => $this->mode->id,
            'payment_type' => 'mixed',
        ];

        $this->account = CashAccount::operating();
    }

    private Stage $stage;

    private Grade $grade;

    private SchoolClass $class;

    private EnrollmentMode $mode;

    private function openCashSession(): void
    {
        app(CashSessionService::class)->open($this->account, $this->accountant);
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

    private function tuitionFee(string $monthlyUnit = '2000.00'): Fee
    {
        $fee = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $monthlyUnit, 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'payment_period' => 'monthly', 'grade_group' => '1–4 классы',
        ]);

        return $fee;
    }

    /** @return array{Fee, int} */
    private function transportFee(string $quarterlyUnit = '1500.00'): array
    {
        $fee = Fee::create(['name_ru' => 'Трансфер', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '0.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'quarterly']);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $quarterlyUnit, 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'quarterly',
        ]);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => bcdiv($quarterlyUnit, '3', 2), 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly',
        ]);
        $routeId = DB::table('transport_routes')->insertGetId(['name' => 'Маршрут 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return [$fee, $routeId];
    }

    /** @return array{Fee, MealPlan} */
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

    /** 1-2: GET renders and stays under a documented query ceiling, regardless of catalog size. */
    public function test_get_page_renders_within_a_bounded_query_ceiling_regardless_of_catalog_size(): void
    {
        // A deliberately larger-than-typical catalog: proves the ceiling
        // holds independent of how many Fees/routes/plans exist, not just
        // for this test's own small fixture set.
        for ($i = 0; $i < 25; $i++) {
            $fee = Fee::create(['name_ru' => "Fee {$i}", 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => true]);
            FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '100.00', 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));
        $response->assertOk();

        // Documented ceiling: comfortably above this fixture's own query
        // count (measured at 18) to avoid brittleness on unrelated schema
        // changes, but a small, fixed constant — the whole point is that
        // this must NEVER scale with catalog size again.
        $this->assertLessThanOrEqual(30, count($queries), 'GET /dashboard/quick-registration must stay within a bounded, catalog-size-independent query ceiling: '.count($queries).' queries issued');
    }

    /** 1: confirms the ceiling is independent of Fee count specifically (the confirmed N+1). */
    public function test_get_page_query_count_does_not_scale_with_fee_count(): void
    {
        $countFor = function (int $feeCount) {
            $admin = User::factory()->create(['is_active' => true]);
            $admin->assignRole('accountant');
            $admin->givePermissionTo('manage invoices');
            for ($i = 0; $i < $feeCount; $i++) {
                $fee = Fee::create(['name_ru' => "Fee {$i}", 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => true]);
                FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '100.00', 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true]);
            }

            $queries = [];
            DB::listen(function ($query) use (&$queries) {
                $queries[] = $query->sql;
            });
            $this->actingAs($admin)->get(route('dashboard.quick-registration.create'))->assertOk();

            return count($queries);
        };

        $small = $countFor(3);
        $large = $countFor(25);

        $this->assertSame($small, $large, "GET query count must be identical regardless of Fee catalog size (was O(N) before this fix): {$small} vs {$large}");
    }

    public function test_realistic_five_service_registration_with_partial_tuition_payment(): void
    {
        $this->openCashSession();
        $registration = $this->registrationFee('7000.00');
        $tuition = $this->tuitionFee('2000.00');
        [$transport, $routeId] = $this->transportFee('1500.00');
        [$food, $plan] = $this->foodFee('85.00');
        $uniform = $this->uniformFee();
        $productId = $this->sellableUniformItem($uniform, 'Майка', '14', '500.00');

        // Tuition's own monthly group totals 2000 x 9 = 18000; only 2000 (one
        // month) is paid now — a genuine PARTIAL payment against a periodic
        // service, the scenario explicitly named in the remediation brief.
        $queries = [];
        $capturing = true;
        DB::listen(function ($query) use (&$queries, &$capturing) {
            if ($capturing) {
                $queries[] = $query->sql;
            }
        });

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '2000.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
            ['fee_id' => $transport->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'quarterly', 'transport_area' => 'Зона 1', 'transport_route_id' => $routeId],
            ['fee_id' => $food->id, 'quantity' => 1, 'paid_now' => '85.00', 'meal_plan_id' => $plan->id, 'food_duration_mode' => 'day', 'food_date' => '2026-08-03'],
            ['fee_id' => $uniform->id, 'paid_now' => '500.00', 'uniform_items' => [['uniform_product_id' => $productId, 'quantity' => 1]]],
        ], ['payment_method' => 'cash', 'cash_account_id' => $this->account->id]));
        $capturing = false;

        $response->assertSessionHasNoErrors();

        // 3: registration succeeds — exactly one of everything expected.
        $invoice = Invoice::sole();
        $this->assertSame(5, InvoiceItem::count());
        $this->assertSame(1, Student::count());
        $this->assertSame(5, StudentServiceSubscription::count());

        // 4/5: invoice + payment totals.
        // Registration 7000 + Tuition 18000 (2000x9) + Transport 4500 (1500x3)
        // + Food 85 + Uniform 500 = 30085.
        $this->assertSame('30085.00', $invoice->total_amount);
        // Paid now: 7000 + 2000 + 0 + 85 + 500 = 9585.
        $paidNow = '9585.00';
        $this->assertSame(0, bccomp((string) $invoice->fresh()->paid_amount, $paidNow, 2));
        $allocatedTotal = PaymentAllocation::query()
            ->join('invoice_payments', 'invoice_payments.id', '=', 'payment_allocations.invoice_payment_id')
            ->where('invoice_payments.invoice_id', $invoice->id)
            ->sum('payment_allocations.amount');
        $this->assertSame(0, bccomp((string) $allocatedTotal, $paidNow, 2), 'persisted allocations must sum exactly to paid_now');

        // 6: outstanding Tuition balance — 18000 total, 2000 paid, 16000 outstanding.
        $tuitionItem = InvoiceItem::where('fee_id', $tuition->id)->sole();
        $this->assertSame('18000.00', $tuitionItem->amount);
        $this->assertSame('2000.00', $tuitionItem->paid_amount);
        $this->assertSame('16000.00', $tuitionItem->remaining_amount);

        // 7: installments — the once-bucket (Registration+Uniform, 1) +
        // Tuition's monthly group (9) + Transport's quarterly group (3) +
        // Food's own lump sum (1) = 14.
        $this->assertSame(14, InvoiceInstallment::where('invoice_id', $invoice->id)->count());
        // Every installment carries a real sequence/due_date/amount — the
        // bulk-insert + re-fetch path must not leave any field unset.
        InvoiceInstallment::where('invoice_id', $invoice->id)->get()->each(function (InvoiceInstallment $installment) {
            $this->assertNotNull($installment->sequence);
            $this->assertNotNull($installment->due_date);
            $this->assertNotNull($installment->amount);
            $this->assertNotNull($installment->created_at);
            $this->assertContains($installment->status, [InvoiceInstallment::STATUS_PENDING, InvoiceInstallment::STATUS_PAID]);
        });
        // Exactly one Tuition-group installment (the first, due on the
        // registration date) is fully settled by the 2000 payment; the
        // rest remain pending.
        $tuitionCoverage = ServiceCoverage::where('fee_id', $tuition->id)->sole();
        $settledPeriods = InstallmentCoveragePeriod::where('service_coverage_id', $tuitionCoverage->id)
            ->get()->filter(fn ($period) => bccomp((string) $period->netSettledAmount(), '0.00', 2) > 0);
        $this->assertCount(1, $settledPeriods, 'exactly one Tuition period should be settled by the 2000 partial payment');

        // 8: service coverage — one per periodic Fee (Tuition, Transport,
        // Food); none for Registration/Uniform (once-only).
        $this->assertSame(3, ServiceCoverage::count());
        $this->assertSame(9, InstallmentCoveragePeriod::where('service_coverage_id', $tuitionCoverage->id)->count());
        $transportCoverage = ServiceCoverage::where('fee_id', $transport->id)->sole();
        $this->assertSame(3, InstallmentCoveragePeriod::where('service_coverage_id', $transportCoverage->id)->count());

        // 8b: cash movement — one CashTransaction per payment() call (2:
        // mixed_once, mixed_periods), summing to paid_now, and the cash
        // account's own balance incremented by exactly that total.
        $cashTransactions = \App\Models\CashTransaction::where('cash_account_id', $this->account->id)->get();
        $this->assertSame(2, $cashTransactions->count());
        $this->assertSame(0, bccomp((string) $cashTransactions->sum('amount'), $paidNow, 2));
        $this->assertSame(0, bccomp((string) $this->account->fresh()->balance, $paidNow, 2));

        // Query-count protection: this exact scenario, end to end (student/
        // enrollment/invoice/items/subscriptions/coverage/installments/
        // payment), must stay within a documented ceiling — the important
        // claim is that this number is now a small, bounded, mostly-fixed
        // cost per registration, never an unbounded per-period/per-line
        // explosion (period_count no longer appears anywhere in this
        // count: 9+3 installments and 9+3+1 coverage periods are each
        // written via ONE bulk statement, not one query per row). Measured
        // at 238 for this exact scenario after the second optimization
        // pass (down from 253 after the first pass, 286 at the PR #46
        // baseline) — ceilinged with headroom above the measured value to
        // avoid brittleness on unrelated schema/behavior changes.
        $this->assertLessThanOrEqual(250, count($queries), 'a realistic 5-service Quick Registration must stay within a bounded query ceiling, not scale with period/installment count: '.count($queries).' queries issued');
    }

    /** 10 (Transport): Operations' own capacity enforcement is untouched by any of this pass's changes. */
    public function test_transport_capacity_validation_remains_intact(): void
    {
        $this->accountant->givePermissionTo(\App\Support\TransportPermissions::MANAGE_ASSIGNMENTS);
        [, $routeId] = $this->transportFee('1500.00');
        $route = \App\Models\TransportRoute::findOrFail($routeId);
        $bus = \App\Models\Bus::create(['vehicle_code' => 'BUS-PERF-1', 'is_active' => true, 'student_capacity' => 1, 'passenger_capacity' => 1]);

        $makeEnrollment = function (string $name): \App\Models\Enrollment {
            $student = \App\Models\Student::create(['name' => $name, 'status' => \App\Models\Student::STATUS_ACTIVE]);

            return \App\Models\Enrollment::create([
                'student_id' => $student->id, 'academic_year_id' => $this->year->id, 'enrollment_mode_id' => $this->mode->id,
                'stage_id' => $this->stage->id, 'grade_id' => $this->grade->id, 'class_id' => $this->class->id,
                'enrolled_at' => '2026-08-01', 'status' => 'active', 'is_active' => true,
            ]);
        };

        $enrollment = $makeEnrollment('Первый');
        app(\App\Services\Transport\TransportAssignmentService::class)->assign($enrollment, $route, $bus, [
            'effective_from' => '2026-08-01',
        ], $this->accountant);

        $secondEnrollment = $makeEnrollment('Второй');

        $this->expectException(\App\Exceptions\TransportCapacityExceeded::class);
        app(\App\Services\Transport\TransportAssignmentService::class)->assign($secondEnrollment, $route, $bus, [
            'effective_from' => '2026-08-01',
        ], $this->accountant);
    }

    public function test_failure_rolls_back_completely_with_no_partial_records(): void
    {
        $this->openCashSession();
        $registration = $this->registrationFee('7000.00');
        $tuition = $this->tuitionFee('2000.00');

        // An invalid billing_period for Tuition's calendar strategy fails
        // validation deep inside issuance, after Student/Enrollment/some
        // InvoiceItems would already exist in an unguarded implementation —
        // proving the whole outer transaction still rolls back completely.
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'yearly', 'grade_group' => '1–4 классы'],
        ], ['payment_method' => 'cash', 'cash_account_id' => $this->account->id]));

        $response->assertSessionHasErrors();
        $this->assertSame(0, Student::count());
        $this->assertSame(0, \App\Models\Enrollment::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoiceItem::count());
        $this->assertSame(0, StudentServiceSubscription::count());
        $this->assertSame(0, ServiceCoverage::count());
        $this->assertSame(0, InvoiceInstallment::count());
        $this->assertSame(0, InstallmentCoveragePeriod::count());
    }

    public function test_retry_with_same_idempotency_token_does_not_duplicate_records(): void
    {
        $this->openCashSession();
        $registration = $this->registrationFee('7000.00');
        $token = (string) \Illuminate\Support\Str::uuid();

        $payload = $this->payload([
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
        ], ['payment_method' => 'cash', 'cash_account_id' => $this->account->id, 'idempotency_token' => $token]);

        $first = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload);
        $first->assertSessionHasNoErrors();

        $second = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload);
        $second->assertSessionHasNoErrors();

        $this->assertSame(1, Student::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, InvoiceItem::count());
    }
}
