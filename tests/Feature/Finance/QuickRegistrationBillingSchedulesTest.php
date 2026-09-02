<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\MealPlan;
use App\Models\PaymentPlan;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finance V2, Phase 2B (docs/finance-v2-architecture.md) — service-aware
 * billing schedules, HTTP-level integration through Quick Registration.
 *
 * Covers: Tuition (monthly/quarterly/yearly), Registration (one-time only,
 * blocked from calendar schedules), Transport and Food (their own
 * explicitly-configured allowed periods), zero-payment schedule generation,
 * full-payment settlement across every generated installment (the §4
 * default), rejection of a payment that doesn't exactly cover a whole
 * number of installments, and the direct regression test against the
 * originally-reported UAT bug (a PaymentPlan must be explicitly assigned to
 * a Fee, never offered globally).
 *
 * Complements InstallmentPlanServiceCalendarTest (pure generator arithmetic)
 * and the existing QuickRegistration*Test files (unaffected regression,
 * separately re-run).
 */
class QuickRegistrationBillingSchedulesTest extends TestCase
{
    use RefreshDatabase;

    protected User $accountant;

    protected array $base;

    protected CashAccount $account;

    protected AcademicYear $year;

    protected Grade $grade;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');

        $year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $stage->id, 'level' => 1]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'А', 'name_ru' => 'А', 'name_ar' => 'A', 'is_active' => true]);
        $mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очная форма', 'is_active' => true]);
        $this->year = $year;
        $this->grade = $grade;

        $this->account = CashAccount::operating();
        app(CashSessionService::class)->open($this->account, $this->accountant);

        $this->base = [
            'student_last_name_ru' => 'Сидорова', 'student_first_name_ru' => 'Мария',
            'phone' => '+20 100 111 2233', 'registration_date' => '2026-08-15',
            'academic_year_id' => $year->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id,
            'class_id' => $class->id, 'enrollment_mode_id' => $mode->id,
        ];
    }

    private function fee(string $name, string $category, string $amount, array $allowedPeriods = []): Fee
    {
        $fee = Fee::create(['name_ru' => $name, 'category' => $category, 'amount' => $amount, 'is_active' => true]);
        foreach ($allowedPeriods as $period) {
            $fee->billingPeriods()->create(['billing_period' => $period]);
        }

        return $fee;
    }

    /**
     * Finance V2, Phase 2D corrective pass — automatic ServiceCoverage
     * creation is now a hard financial invariant for every calendar-billed
     * Fee (P0 Blocker 3: no catch-log-and-skip), which correctly requires
     * a REAL, dimensionally-matching, payment_period-tagged monthly
     * FeePrice to exist — the flat Fee.amount fallback these fixtures
     * originally relied on (pre-dating ServiceCoverage entirely) can no
     * longer carry a periodic invoice on its own, since there would be no
     * real tariff to build coverage or future tariff adjustments from.
     * This helper gives each Tuition fixture that real tariff instead of
     * silently depending on the legacy flat-amount fallback.
     */
    private function tuitionPrice(Fee $fee, string $amount): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly',
            'grade_id' => $this->grade->id, 'amount' => $amount, 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
    }

    // ----- Tuition: monthly/quarterly/yearly ----------------------------------

    public function test_tuition_monthly_schedule_via_quick_registration(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1100.00', ['monthly']);
        $this->tuitionPrice($fee, '1100.00');
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $invoice = Invoice::sole();
        // August 2026 through June 2027 inclusive = 11 months.
        $this->assertSame(11, $invoice->installments()->count());
    }

    public function test_tuition_quarterly_schedule_via_quick_registration(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1200.00', ['quarterly']);
        // Quarterly pricing is always DERIVED from a real monthly tariff
        // (Phase 2D item 1) — never a flat Fee.amount fallback — and the
        // same monthly tariff also serves as automatic coverage's
        // adjustment basis (Phase 2D corrective pass, P0 Blocker 2).
        $this->tuitionPrice($fee, '400.00');
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'quarterly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00', 'payment_period' => 'quarterly']],
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        // Registering mid-August (Q3) through year-end June (Q2) = 4 quarters.
        $this->assertSame(4, Invoice::sole()->installments()->count());
    }

    public function test_tuition_yearly_schedule_via_quick_registration(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1500.00', ['yearly']);
        // The actual yearly-collected package price (charged tariff).
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'yearly', 'grade_id' => $this->grade->id, 'amount' => '1500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        // A SEPARATE monthly tariff as automatic coverage's adjustment
        // basis (Phase 2D corrective pass, P0 Blocker 2 — never derived
        // by dividing the yearly package price).
        $this->tuitionPrice($fee, '150.00');
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'yearly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00', 'payment_period' => 'yearly']],
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $invoice = Invoice::sole();
        $this->assertSame(1, $invoice->installments()->count());
        $this->assertSame('1500.00', $invoice->installments()->sole()->amount);
    }

    public function test_a_period_not_allowed_for_the_fee_is_rejected(): void
    {
        // Only 'monthly' allowed — 'quarterly' must be rejected.
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1200.00', ['monthly']);
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'quarterly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ]);

        $response->assertSessionHasErrors('billing_period');
        $this->assertDatabaseCount('invoices', 0);
    }

    // ----- Registration: one-time only -----------------------------------------

    public function test_registration_fee_is_blocked_from_a_calendar_schedule(): void
    {
        // Registration is never granted any calendar period — 'once' only,
        // exactly per the confirmed business rule — so a calendar attempt
        // must be rejected regardless of which period is requested.
        $registration = $this->fee('Регистрационный взнос', Fee::CATEGORY_REGISTRATION, '500.00', ['once']);
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ]);

        $response->assertSessionHasErrors('billing_period');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_registration_bundled_with_tuition_blocks_the_whole_invoice_from_a_calendar_schedule(): void
    {
        // Registration's allowed set ({once}) has no overlap with any
        // calendar period, so bundling it with Tuition in one submission
        // must reject a calendar schedule for the WHOLE invoice — the
        // current single-schedule-per-invoice architecture means "Registration
        // is one-time only" is enforced by requiring one_time/plan for any
        // invoice that includes it, not by silently splitting the invoice.
        $registration = $this->fee('Регистрационный взнос', Fee::CATEGORY_REGISTRATION, '500.00', ['once']);
        $tuition = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1000.00', ['monthly']);
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [
                ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00'],
                ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00'],
            ],
        ]);

        $response->assertSessionHasErrors('billing_period');
        $this->assertDatabaseCount('invoices', 0);
    }

    // ----- Transport / Food: their own allowed periods --------------------------

    public function test_transport_monthly_schedule_via_quick_registration(): void
    {
        $transport = $this->fee('Транспорт', Fee::CATEGORY_TRANSPORT, '600.00', ['monthly']);
        FeePrice::create([
            'fee_id' => $transport->id, 'academic_year_id' => $this->base['academic_year_id'], 'amount' => '600.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly',
        ]);
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [['fee_id' => $transport->id, 'quantity' => 1, 'paid_now' => '0.00', 'transport_area' => 'Зона 1', 'transport_route_id' => $this->transportRoute()->id, 'payment_period' => 'monthly']],
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(11, Invoice::sole()->installments()->count());
    }

    public function test_food_yearly_schedule_via_quick_registration(): void
    {
        $mealPlan = MealPlan::create(['name_ru' => 'Полный день', 'meal_type' => MealPlan::TYPE_BOTH, 'period' => MealPlan::PERIOD_MONTHLY, 'price' => '900.00', 'is_active' => true]);
        $food = $this->fee('Питание', Fee::CATEGORY_FOOD, '900.00', ['yearly']);
        // The actual yearly-collected package price (charged tariff).
        FeePrice::create([
            'fee_id' => $food->id, 'academic_year_id' => $this->base['academic_year_id'], 'amount' => '900.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => (string) $mealPlan->id, 'payment_period' => 'yearly',
        ]);
        // A SEPARATE daily tariff as automatic coverage's adjustment basis
        // (Phase 2D corrective pass, P0 Blocker 2/3 — Food's coverage
        // granularity is always daily, independent of collection cadence,
        // and must never be invented by dividing the yearly package price).
        FeePrice::create([
            'fee_id' => $food->id, 'academic_year_id' => $this->base['academic_year_id'], 'amount' => '45.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => (string) $mealPlan->id, 'payment_period' => 'daily',
        ]);
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'yearly',
            'services' => [['fee_id' => $food->id, 'quantity' => 1, 'paid_now' => '0.00', 'meal_plan_id' => $mealPlan->id, 'payment_period' => 'yearly']],
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $invoice = Invoice::sole();
        $this->assertSame(1, $invoice->installments()->count());
        $this->assertSame('900.00', $invoice->installments()->sole()->amount);
    }

    public function test_food_does_not_inherit_transport_or_tuition_allowed_periods(): void
    {
        // Food is only granted 'monthly' — 'quarterly' (allowed for a
        // DIFFERENT fee elsewhere in the suite) must not leak across.
        $mealPlan = MealPlan::create(['name_ru' => 'Обед', 'meal_type' => MealPlan::TYPE_BOTH, 'period' => MealPlan::PERIOD_MONTHLY, 'price' => '400.00', 'is_active' => true]);
        $food = $this->fee('Питание', Fee::CATEGORY_FOOD, '400.00', ['monthly']);
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'quarterly',
            'services' => [['fee_id' => $food->id, 'quantity' => 1, 'paid_now' => '0.00', 'meal_plan_id' => $mealPlan->id]],
        ]);

        $response->assertSessionHasErrors('billing_period');
        $this->assertDatabaseCount('invoices', 0);
    }

    // ----- zero payment still generates the correct schedule --------------------

    public function test_zero_payment_still_generates_the_full_future_schedule(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1100.00', ['monthly']);
        $this->tuitionPrice($fee, '1100.00');
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame(11, $invoice->installments()->count());
        $this->assertDatabaseCount('invoice_payments', 0);
        // Every installment remains fully outstanding — nothing skipped or deferred.
        $this->assertTrue($invoice->installments()->get()->every(fn ($i) => bccomp((string) $i->paid_amount, '0.00', 2) === 0));
    }

    // ----- full payment settles every generated installment (§4 default) --------

    public function test_full_payment_settles_every_generated_installment(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1000.00', ['monthly']);
        // Registering right at year-start keeps the month count small:
        // Aug..Jun = 11 months. Phase 2D corrective pass (P0 Blocker 1):
        // pricing is now unit x period-count, so the FeePrice IS the
        // per-month unit (1000.00), giving a clean total of 11000.00 (no
        // remainder-absorption needed — each installment is exactly the
        // unit price).
        $this->tuitionPrice($fee, '1000.00');
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), array_replace($this->base, ['registration_date' => '2026-08-01']) + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '11000.00']],
            'cash_account_id' => $this->account->id, 'payment_method' => 'cash',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $invoice = Invoice::sole();
        $installments = $invoice->installments()->get();
        $this->assertSame(11, $installments->count());
        $this->assertTrue($installments->every(fn ($i) => bccomp((string) $i->remaining_amount, '0.00', 2) === 0), 'every installment must be fully settled');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        // One InvoicePayment per settled installment (see the service's own
        // §4 note: each installment needs its own invoice_installment_id-
        // tied InvoicePayment row).
        $this->assertSame(11, InvoicePayment::count());
        $this->assertSame('11000.00', bcadd((string) InvoicePayment::sum('amount'), '0', 2));
    }

    // ----- request-level idempotency for multi-installment full payment (review finding M3) -----

    public function test_replaying_the_same_submission_does_not_duplicate_installment_payments(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1000.00', ['monthly']);
        $this->tuitionPrice($fee, '1000.00');
        // Aug..Jun = 11 months x 1000.00/month = 11000.00 total (Phase 2D
        // corrective pass, P0 Blocker 1 — see test above).
        $payload = array_replace($this->base, ['registration_date' => '2026-08-01']) + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '11000.00']],
            'cash_account_id' => $this->account->id, 'payment_method' => 'cash',
            // Fixed, explicit token — simulates the SAME already-rendered
            // form (same hidden idempotency_token field) being submitted
            // twice: a double-click, or an automatic retry of the same POST.
            'idempotency_token' => 'test-fixed-idempotency-token-0001',
        ];

        $first = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload);
        $first->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, Invoice::count());
        $this->assertSame(11, InvoicePayment::count());
        $firstInvoiceId = Invoice::sole()->id;

        // The exact same payload, same token, submitted a second time.
        $second = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload);

        // Corrective pass #2 (HIGH 2 — Quick Registration operation-level
        // idempotency): a genuine retry (same token, same payload) now
        // succeeds as a TRUE replay — register() itself recognizes the
        // exact same operation via quick_registration_operations before
        // Student creation is ever reached, and returns the ORIGINAL
        // Student/Enrollment/Invoice directly, never attempting a second
        // Student/Invoice/payment at all. This closes the exact gap pass
        // #1's invoice-level-only idempotency left open (invoice-level
        // idempotency alone could never prevent a duplicate Student, since
        // it only ever engaged after Student creation had already
        // happened) — a retry is no longer merely "rejected without
        // duplicating," it now genuinely does nothing new at all.
        $second->assertSessionHasNoErrors()->assertRedirect();

        // No duplication anywhere: same single invoice, same 11 payments —
        // not 2 invoices, not 22 payments, not 2 students.
        $this->assertSame(1, Invoice::count(), 'the retry must create nothing new at all');
        $this->assertSame(1, \App\Models\Student::count());
        $this->assertSame($firstInvoiceId, Invoice::sole()->id);
        $this->assertSame(11, InvoicePayment::count(), 'no duplicate InvoicePayment rows from the retry');
        $this->assertSame('11000.00', bcadd((string) InvoicePayment::sum('amount'), '0', 2), 'total collected must still equal exactly one full payment, not two');
    }

    public function test_replaying_with_a_genuinely_different_payload_under_the_same_token_is_rejected(): void
    {
        // Corrective pass #2 (HIGH 2/HIGH 3): the SAME idempotency_token
        // reused for a MATERIALLY different submission (a different fee
        // this time) must be rejected outright — never silently treated
        // as a replay of the first, and never silently accepted as an
        // unrelated second registration under the same key either.
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1000.00', ['monthly']);
        $this->tuitionPrice($fee, '1000.00');
        $otherFee = $this->fee('Обучение (другая)', Fee::CATEGORY_TUITION, '1000.00', ['monthly']);
        $this->tuitionPrice($otherFee, '2000.00');

        $basePayload = array_replace($this->base, ['registration_date' => '2026-08-01']) + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'cash_account_id' => $this->account->id, 'payment_method' => 'cash',
            'idempotency_token' => 'test-fixed-idempotency-token-0002',
        ];

        $first = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $basePayload + [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ]);
        $first->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Invoice::count());

        $second = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $basePayload + [
            'services' => [['fee_id' => $otherFee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ]);
        $second->assertSessionHasErrors('idempotency_key');
        $this->assertSame(1, Invoice::count(), 'the rejected retry must create nothing new');
        $this->assertSame(1, \App\Models\Student::count());
    }

    public function test_partial_payment_not_covering_a_whole_number_of_installments_is_rejected(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1100.00', ['monthly']);
        // First installment is 100.00/month x 11 months = 1100.00 total;
        // pay 150.00 — covers the first fully but only partially covers
        // the second. Must reject, not silently apply a partial
        // second-installment payment.
        $this->tuitionPrice($fee, '100.00');
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), array_replace($this->base, ['registration_date' => '2026-08-01']) + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '150.00']],
            'cash_account_id' => $this->account->id, 'payment_method' => 'cash',
        ]);

        $response->assertSessionHasErrors('services');
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_payments', 0);
    }

    // ----- PaymentPlan scoping regression (the originally-reported UAT bug) -----

    public function test_a_fee_with_no_assigned_plan_offers_no_installment_plan(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1000.00', ['custom_plan']);
        PaymentPlan::create(['name_ru' => 'Другой план (не назначен этой услуге)', 'is_active' => true])
            ->installments()->create(['name_ru' => 'Единственный этап', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100']);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'plan', 'payment_plan_id' => PaymentPlan::sole()->id,
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ]);

        // The plan exists and is active, but was never assigned to this
        // fee — must be rejected, not silently accepted the way the
        // originally-reported bug allowed any active plan through.
        $response->assertSessionHasErrors('payment_plan_id');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_a_fee_with_an_assigned_plan_accepts_only_that_plan(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1000.00', ['custom_plan']);
        $assigned = PaymentPlan::create(['name_ru' => 'Назначенный план', 'is_active' => true]);
        $assigned->installments()->create(['name_ru' => 'Единственный этап', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100']);
        $fee->assignedPaymentPlans()->attach($assigned->id);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'plan', 'payment_plan_id' => $assigned->id,
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Invoice::sole()->installments()->count());
    }

    private function transportRoute(): \stdClass
    {
        $id = \Illuminate\Support\Facades\DB::table('transport_routes')->insertGetId([
            'name' => 'Маршрут 1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (object) ['id' => $id];
    }

    // ----- Finance metadata preservation (corrective pass #2, HIGH 4) -----

    public function test_quick_registration_preserves_finance_pricing_metadata_alongside_its_own_admissions_metadata_monthly(): void
    {
        // Real end-to-end through register() (the HTTP endpoint), not
        // InvoiceIssuanceService::issue() directly — this is exactly the
        // path that previously overwrote the Finance audit metadata.
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1000.00', ['monthly']);
        $this->tuitionPrice($fee, '1000.00');
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00', 'grade_group' => null]],
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect();

        $item = Invoice::sole()->items->sole();
        // Finance pricing/coverage audit metadata — must survive.
        $this->assertSame('1000.00', $item->metadata['unit_tariff'] ?? null);
        $this->assertSame('monthly', $item->metadata['billing_unit'] ?? null);
        $this->assertArrayHasKey('coverage_start', $item->metadata);
        $this->assertArrayHasKey('coverage_end', $item->metadata);
        $this->assertArrayHasKey('fee_price_id', $item->metadata);
        // Admissions-domain metadata this same write step adds — must
        // ALSO survive, alongside the Finance fields above, neither
        // clobbering the other.
        $this->assertArrayHasKey('first_last_month', $item->metadata);
    }

    public function test_quick_registration_preserves_finance_pricing_metadata_alongside_its_own_admissions_metadata_transport(): void
    {
        $transport = $this->fee('Транспорт', Fee::CATEGORY_TRANSPORT, '600.00', ['monthly']);
        FeePrice::create([
            'fee_id' => $transport->id, 'academic_year_id' => $this->base['academic_year_id'], 'amount' => '600.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly',
        ]);
        $route = $this->transportRoute();
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [['fee_id' => $transport->id, 'quantity' => 1, 'paid_now' => '0.00', 'transport_area' => 'Зона 1', 'transport_route_id' => $route->id, 'payment_period' => 'monthly']],
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect();

        $item = Invoice::sole()->items->sole();
        $this->assertSame('600.00', $item->metadata['unit_tariff'] ?? null);
        $this->assertSame('monthly', $item->metadata['billing_unit'] ?? null);
        $this->assertArrayHasKey('coverage_start', $item->metadata);
        // Admissions-domain (Transport-specific) metadata — must ALSO
        // survive alongside the Finance fields above.
        $this->assertSame((string) $route->id, (string) ($item->metadata['route_id'] ?? null));
        $this->assertSame('Зона 1', $item->metadata['area'] ?? null);
    }

    public function test_quick_registration_preserves_finance_pricing_metadata_for_quarterly_billing(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1200.00', ['quarterly']);
        $this->tuitionPrice($fee, '400.00');
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'quarterly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00', 'payment_period' => 'quarterly']],
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect();

        $item = Invoice::sole()->items->sole();
        $this->assertSame('quarterly', $item->metadata['billing_unit'] ?? null);
        $this->assertTrue($item->metadata['derived'] ?? false);
        $this->assertArrayHasKey('derived_from_fee_price_id', $item->metadata);
        // Coverage for quarterly billing takes the adjustment-basis path
        // — this metadata is written by createAutomaticCoverage(), even
        // later than the pricing metadata above, and must ALSO survive
        // this same overwrite-prone update() call.
        $this->assertArrayHasKey('adjustment_basis_fee_price_id', $item->metadata);
        $this->assertSame('monthly', $item->metadata['adjustment_basis_period'] ?? null);
    }

    public function test_quick_registration_preserves_finance_pricing_metadata_for_yearly_billing(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1500.00', ['yearly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->base['academic_year_id'], 'payment_period' => 'yearly', 'grade_id' => $this->grade->id, 'amount' => '1500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->tuitionPrice($fee, '150.00');
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'yearly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00', 'payment_period' => 'yearly']],
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect();

        $item = Invoice::sole()->items->sole();
        $this->assertSame('yearly', $item->metadata['billing_unit'] ?? null);
        $this->assertArrayHasKey('adjustment_basis_fee_price_id', $item->metadata);
        $this->assertSame('150.00', $item->metadata['adjustment_basis_unit_amount'] ?? null);
    }

    /**
     * Corrective pass #3 (P2 — idempotency hash normalization). Worked
     * example I: the SAME token, an identically-composed bundled
     * submission whose 'services' array is simply reordered between the
     * two attempts, must replay the SAME operation — never fail safe on
     * order alone. Verified safe first (not assumed): StoreQuickStudentRegistrationRequest
     * enforces 'services.*.fee_id' => 'distinct', so a repeated fee_id in
     * one submission is structurally impossible, making fee_id a safe,
     * always-unique sort key for this specific caller.
     */
    public function test_reordering_the_services_array_under_the_same_token_still_replays_the_same_operation(): void
    {
        $tuition = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1000.00', ['monthly']);
        $this->tuitionPrice($tuition, '1000.00');
        $transport = $this->fee('Транспорт', Fee::CATEGORY_TRANSPORT, '400.00', ['monthly']);
        \App\Models\FeePrice::create(['fee_id' => $transport->id, 'academic_year_id' => $this->base['academic_year_id'], 'amount' => '400.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true, 'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly']);
        $token = 'reorder-token-'.uniqid();

        $servicesAB = [
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00'],
            ['fee_id' => $transport->id, 'quantity' => 1, 'paid_now' => '0.00', 'transport_area' => 'Зона 1', 'transport_route_id' => $this->transportRoute()->id, 'payment_period' => 'monthly'],
        ];
        $first = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => $servicesAB, 'idempotency_token' => $token,
        ]);
        $first->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Invoice::count());
        $firstStudentId = \App\Models\Student::sole()->id;

        // Identical content, services array REORDERED (Transport first).
        $servicesBA = array_reverse($servicesAB);
        $second = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => $servicesBA, 'idempotency_token' => $token,
        ]);
        $second->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, Invoice::count(), 'a harmless reordering must replay, never create a second registration');
        $this->assertSame(1, \App\Models\Student::count());
        $this->assertSame($firstStudentId, \App\Models\Student::sole()->id);
    }
}
