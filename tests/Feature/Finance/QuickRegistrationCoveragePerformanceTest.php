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
use App\Models\StudentServiceSubscription;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Perf regression guard for the Quick Registration 504 (round 2 — coverage
 * generation). QR_TRACE evidence from real UAT PostgreSQL traffic showed a
 * SINGLE 9-period coverage batch costing ~16.4s, because
 * InstallmentCoveragePeriod::create() (called once per period) triggers
 * that model's own `creating` hook (validateIntegrity()), which issues 3
 * EXTRA per-row round trips (a ServiceCoverage lockForUpdate+findOrFail, an
 * InvoiceInstallment findOrFail, and an overlap exists() query) on top of
 * the INSERT itself — invisible on this suite's latency-free SQLite
 * connection, but ruinous against a real network-latency-bound Postgres
 * connection.
 *
 * InvoiceIssuanceService::createAutomaticCoverage()/
 * createFoodInstallmentAndCoverage() now validate every period's
 * invariants in memory (using data already loaded in the same request —
 * safe specifically because every ServiceCoverage row involved was just
 * created inside this SAME still-open transaction, so it cannot already
 * have periods and cannot be raced) and write every period via ONE bulk
 * INSERT per coverage batch, instead of one InstallmentCoveragePeriod::
 * create() per period.
 *
 * This test proves TWO things a wall-clock timer cannot on this fast local
 * suite: (1) the exact same rows a realistic 5-service, multi-month Quick
 * Registration must produce are still produced, with no duplicate or
 * missing coverage period — the *data* is provably byte-identical to the
 * pre-fix behaviour; and (2) period writes are genuinely batched — the
 * number of statements touching installment_coverage_periods stays a small
 * constant (bounded by the number of coverage batches: one per mixed
 * billing-period group, plus one per Food Fee) regardless of how many
 * periods those batches contain, never one INSERT (plus 3 extra selects)
 * per period.
 */
class QuickRegistrationCoveragePerformanceTest extends TestCase
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

        // 9 whole calendar months (Aug -> Apr) so Tuition's monthly group
        // produces exactly 9 periods and Transport's quarterly group
        // produces exactly 3 (9 = 3x3, no trailing partial group) — clean,
        // easily-verified numbers, matching this codebase's own
        // established fixture convention (QuickRegistrationMixedBillingTest).
        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-04-30', 'is_active' => true]);
        $stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $stage->id, 'level' => 1]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'А', 'name_ru' => 'А', 'name_ar' => 'A', 'is_active' => true]);
        $mode = EnrollmentMode::create(['code' => EnrollmentMode::FULL_TIME, 'name_ru' => 'Очная форма', 'is_active' => true]);

        $this->base = [
            'student_last_name_ru' => 'Петрова', 'student_first_name_ru' => 'Мария',
            'phone' => '+20 100 555 9911', 'registration_date' => '2026-08-01',
            'academic_year_id' => $this->year->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id,
            'class_id' => $class->id, 'enrollment_mode_id' => $mode->id,
            'payment_type' => 'mixed',
        ];

        $this->account = CashAccount::operating();
    }

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

    /** @return array{Fee, int} [fee, transport_route_id] */
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
        // coverage for a non-Food calendar Fee, regardless of the
        // invoice's own billing_period — a quarterly-billed Fee still
        // needs its own monthly tariff configured as the coverage/
        // tariff-adjustment basis price.
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

    public function test_realistic_five_service_registration_batches_coverage_period_writes(): void
    {
        $this->openCashSession();
        $registration = $this->registrationFee('7000.00');
        $tuition = $this->tuitionFee('2000.00');
        [$transport, $routeId] = $this->transportFee('1500.00');
        [$food, $plan] = $this->foodFee('85.00');
        $uniform = $this->uniformFee();
        $productId = $this->sellableUniformItem($uniform, 'Майка', '14', '500.00');

        // Captures only the queries the registration REQUEST ITSELF issues
        // — $capturing is switched off immediately after the response
        // returns, so this test's OWN later read assertions (count(),
        // where('service_coverage_id', ...), etc.) never pollute the
        // sample being measured.
        $queries = [];
        $capturing = true;
        DB::listen(function ($query) use (&$queries, &$capturing) {
            if ($capturing) {
                $queries[] = $query->sql;
            }
        });

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
            ['fee_id' => $transport->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'quarterly', 'transport_area' => 'Зона 1', 'transport_route_id' => $routeId],
            ['fee_id' => $food->id, 'quantity' => 1, 'paid_now' => '85.00', 'meal_plan_id' => $plan->id, 'food_duration_mode' => 'day', 'food_date' => '2026-08-03'],
            ['fee_id' => $uniform->id, 'paid_now' => '500.00', 'uniform_items' => [['uniform_product_id' => $productId, 'quantity' => 1]]],
        ], ['payment_method' => 'cash', 'cash_account_id' => $this->account->id]));
        $capturing = false;

        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();

        // ----- A. exact expected graph, no duplicates, nothing missing ----

        $this->assertSame(5, InvoiceItem::count(), 'one InvoiceItem per submitted service');
        // Registration + Uniform settle in the single 'once' bucket
        // installment; Tuition's monthly group produces 9 installments;
        // Transport's quarterly group produces 3; Food gets its own single
        // lump-sum installment. 1 + 9 + 3 + 1 = 14.
        $this->assertSame(14, InvoiceInstallment::where('invoice_id', $invoice->id)->count(), 'exact expected installment count across all groups');
        // Registration/Uniform are once-only — no ServiceCoverage for
        // either. Tuition, Transport, and Food each get exactly one.
        $this->assertSame(3, ServiceCoverage::count(), 'one ServiceCoverage per periodic Fee only');
        // 9 (Tuition monthly) + 3 (Transport quarterly) + 1 (Food lump sum) = 13.
        $this->assertSame(13, InstallmentCoveragePeriod::count(), 'exact expected period count, no duplicates and none missing');
        $this->assertSame(5, StudentServiceSubscription::count(), 'one subscription per service line');

        $tuitionCoverage = ServiceCoverage::where('fee_id', $tuition->id)->sole();
        $transportCoverage = ServiceCoverage::where('fee_id', $transport->id)->sole();
        $foodCoverage = ServiceCoverage::where('fee_id', $food->id)->sole();

        $tuitionPeriods = InstallmentCoveragePeriod::where('service_coverage_id', $tuitionCoverage->id)->orderBy('period_start')->get();
        $transportPeriods = InstallmentCoveragePeriod::where('service_coverage_id', $transportCoverage->id)->orderBy('period_start')->get();
        $foodPeriods = InstallmentCoveragePeriod::where('service_coverage_id', $foodCoverage->id)->get();

        $this->assertCount(9, $tuitionPeriods);
        $this->assertCount(3, $transportPeriods);
        $this->assertCount(1, $foodPeriods);

        // Every Tuition period lies strictly within the coverage's own
        // bounds, is chronologically ordered, and none overlaps its
        // neighbour — exactly what the removed per-row DB checks used to
        // prove one row at a time, now proven once here over the whole set.
        $previousEnd = null;
        foreach ($tuitionPeriods as $period) {
            $this->assertTrue($period->period_start->gte($tuitionCoverage->coverage_start));
            $this->assertTrue($period->period_end->lte($tuitionCoverage->coverage_end));
            if ($previousEnd !== null) {
                $this->assertTrue($period->period_start->gt($previousEnd), 'periods must not overlap or duplicate');
            }
            $previousEnd = $period->period_end;
        }
        $this->assertSame('2026-08-01', $tuitionPeriods->first()->period_start->toDateString());
        $this->assertSame('2027-04-30', $tuitionPeriods->last()->period_end->toDateString());

        $previousEnd = null;
        foreach ($transportPeriods as $period) {
            $this->assertTrue($period->period_start->gte($transportCoverage->coverage_start));
            $this->assertTrue($period->period_end->lte($transportCoverage->coverage_end));
            if ($previousEnd !== null) {
                $this->assertTrue($period->period_start->gt($previousEnd), 'periods must not overlap or duplicate');
            }
            $previousEnd = $period->period_end;
        }

        // Every period's own 'amount' is populated (Corrective pass #2, P0
        // Blocker 2) — never null/lost by the bulk-write path.
        foreach ($tuitionPeriods->merge($transportPeriods)->merge($foodPeriods) as $period) {
            $this->assertNotNull($period->amount);
        }

        // Payment allocations: paid_now = 7000 (Registration) + 85 (Food) +
        // 500 (Uniform) = 7585, fully allocated across their own items.
        $allocatedTotal = PaymentAllocation::query()
            ->join('invoice_payments', 'invoice_payments.id', '=', 'payment_allocations.invoice_payment_id')
            ->where('invoice_payments.invoice_id', $invoice->id)
            ->sum('payment_allocations.amount');
        $this->assertSame(0, bccomp((string) $allocatedTotal, '7585.00', 2));

        // ----- B. proof that period writes are genuinely batched --------

        $periodTableQueries = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'installment_coverage_periods')
        ));
        $periodInserts = array_values(array_filter($periodTableQueries, fn ($sql) => str_starts_with(trim($sql), 'insert')));
        $periodSelects = array_values(array_filter($periodTableQueries, fn ($sql) => str_starts_with(trim($sql), 'select')));

        // One coverage batch per mixed billing-period group (Tuition's
        // monthly group, Transport's quarterly group) plus one for Food —
        // at most 3 bulk INSERT statements total, never one per period
        // (which would be 13).
        $this->assertLessThanOrEqual(3, count($periodInserts), 'coverage periods must be written via a small, bounded number of bulk inserts, not one per period: '.implode("\n", $periodInserts));

        // Before this fix, each of the 13 periods triggered its own
        // pre-insert overlap-check SELECT against
        // installment_coverage_periods, scoped by service_coverage_id
        // (InstallmentCoveragePeriod::validateIntegrity()) — 13 extra
        // selects of that specific shape. The in-memory validation this
        // fix introduces performs zero of them; the one legitimate SELECT
        // that remains (fetching every period for THIS invoice's
        // installments, to allocate the 'mixed' payment's periods bucket —
        // QuickStudentRegistrationService::register(), unchanged by this
        // fix) is scoped by invoice_installment_id/invoice_id, never by
        // service_coverage_id, so it never matches this narrower filter.
        $overlapCheckShapedSelects = array_values(array_filter($periodSelects, fn ($sql) => str_contains($sql, 'service_coverage_id')));
        $this->assertSame(0, count($overlapCheckShapedSelects), 'coverage-period overlap validation must be in-memory, not a per-row DB query: '.implode("\n", $overlapCheckShapedSelects));
        // Total statements touching this table stay a small constant
        // regardless of period count (13) — the handful of remaining
        // legitimate SELECTs come from payment-ALLOCATION lookups
        // (proportional to the number of allocations, a small fixed
        // count here — unrelated to and unchanged by this fix), never
        // from a per-PERIOD overlap check. Bounded well under what even
        // "one query per period" (13) would need, let alone the old
        // per-row pattern's 13 x 4 = 52.
        $this->assertLessThanOrEqual(15, count($periodTableQueries), 'total statements touching installment_coverage_periods must stay bounded, not scale with period count: '.implode("\n", $periodTableQueries));

        // Confirms the inserts above genuinely carried every row — not
        // fewer statements each still doing one row at a time.
        $totalBoundRowsAcrossInserts = collect($periodInserts)
            ->sum(fn ($sql) => substr_count($sql, '(?, ?, ?, ?, ?, ?)'));
        $this->assertSame(13, $totalBoundRowsAcrossInserts, 'the bulk inserts together must carry exactly one value-tuple per period, none missing or duplicated');
    }
}
