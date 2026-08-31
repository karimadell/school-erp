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

    // ----- Tuition: monthly/quarterly/yearly ----------------------------------

    public function test_tuition_monthly_schedule_via_quick_registration(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1100.00', ['monthly']);
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
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'quarterly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        // Registering mid-August (Q3) through year-end June (Q2) = 4 quarters.
        $this->assertSame(4, Invoice::sole()->installments()->count());
    }

    public function test_tuition_yearly_schedule_via_quick_registration(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1500.00', ['yearly']);
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'yearly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
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
            'option_type' => 'zone', 'option_value' => 'Зона 1',
        ]);
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [['fee_id' => $transport->id, 'quantity' => 1, 'paid_now' => '0.00', 'transport_area' => 'Зона 1', 'transport_route_id' => $this->transportRoute()->id]],
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(11, Invoice::sole()->installments()->count());
    }

    public function test_food_yearly_schedule_via_quick_registration(): void
    {
        $mealPlan = MealPlan::create(['name_ru' => 'Полный день', 'meal_type' => MealPlan::TYPE_BOTH, 'period' => MealPlan::PERIOD_MONTHLY, 'price' => '900.00', 'is_active' => true]);
        $food = $this->fee('Питание', Fee::CATEGORY_FOOD, '900.00', ['yearly']);
        FeePrice::create([
            'fee_id' => $food->id, 'academic_year_id' => $this->base['academic_year_id'], 'amount' => '900.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => (string) $mealPlan->id,
        ]);
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'yearly',
            'services' => [['fee_id' => $food->id, 'quantity' => 1, 'paid_now' => '0.00', 'meal_plan_id' => $mealPlan->id]],
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
        // Registering right at year-start keeps the month count small and
        // the per-period amount a clean number: Aug..Jun = 11 months,
        // 1000.00 / 11 = 90.90 * 10 + 91.00.
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), array_replace($this->base, ['registration_date' => '2026-08-01']) + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '1000.00']],
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
        $this->assertSame('1000.00', bcadd((string) InvoicePayment::sum('amount'), '0', 2));
    }

    // ----- request-level idempotency for multi-installment full payment (review finding M3) -----

    public function test_replaying_the_same_submission_does_not_duplicate_installment_payments(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1000.00', ['monthly']);
        $payload = array_replace($this->base, ['registration_date' => '2026-08-01']) + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '1000.00']],
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

        // The retry must fail cleanly (idempotency-key collision on the
        // first installment) rather than silently duplicating anything —
        // and per InvoicePaymentService::record()'s own idempotency-hash
        // check, the collision is detected against a DIFFERENT invoice's
        // installment (the retry always creates its own new Invoice, since
        // invoice creation itself has no dedup), so the hash necessarily
        // mismatches and record() rejects it. Because register()'s entire
        // body — student, enrollment, invoice, installments, and the
        // payment loop — runs inside one transaction, that rejection rolls
        // back everything the retry attempted, leaving only attempt one's
        // data behind.
        $second->assertSessionHasErrors('idempotency_key');

        // No duplication anywhere: same single invoice, same 11 payments —
        // not 2 invoices, not 22 payments.
        $this->assertSame(1, Invoice::count(), 'the retry\'s own Invoice must have been rolled back, not left as a second row');
        $this->assertSame($firstInvoiceId, Invoice::sole()->id);
        $this->assertSame(11, InvoicePayment::count(), 'no duplicate InvoicePayment rows from the retry');
        $this->assertSame('1000.00', bcadd((string) InvoicePayment::sum('amount'), '0', 2), 'total collected must still equal exactly one full payment, not two');
    }

    public function test_partial_payment_not_covering_a_whole_number_of_installments_is_rejected(): void
    {
        $fee = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1100.00', ['monthly']);
        // First installment is 100.00 (1100/11); pay 150.00 — covers the
        // first fully but only partially covers the second. Must reject,
        // not silently apply a partial second-installment payment.
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
}
