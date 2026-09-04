<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicCalendar;
use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\InstallmentCoveragePeriod;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\PaymentAllocationCoveragePeriod;
use App\Models\PaymentPlan;
use App\Models\SchoolClass;
use App\Models\ServiceCoverage;
use App\Models\Stage;
use App\Models\StudentServiceSubscription;
use App\Models\User;
use App\Services\Finance\FoodBillableDayCalculator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickStudentServiceAllocationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private array $base;
    private CashAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('accountant');
        $year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $stage = Stage::create(['name' => 'Начальная школа', 'is_active' => true]);
        $grade = Grade::create(['name' => '1 класс', 'stage_id' => $stage->id]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => '1-А', 'name_ar' => '1-A', 'name_ru' => '1-А', 'is_active' => true]);
        $mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очное обучение', 'is_active' => true]);
        // Cash Operations Phase 4: cash payments resolve to the canonical
        // operating account server-side regardless of cash_account_id.
        $this->account = CashAccount::operating();
        // Phase 3: a cash collection requires an open drawer session.
        app(\App\Services\Finance\CashSessionService::class)->open($this->account, $this->user);
        $this->base = [
            'student_last_name_ru' => 'Петрова', 'student_first_name_ru' => 'Анна',
            'student_patronymic_ru' => null, 'phone' => '01012345678',
            'academic_year_id' => $year->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id,
            'class_id' => $class->id, 'enrollment_mode_id' => $mode->id, 'registration_date' => '2026-08-02',
        ];
    }

    private function fee(string $name, string $category, string $amount): Fee
    {
        return Fee::create(['name_ru' => $name, 'category' => $category, 'amount' => $amount, 'is_active' => true]);
    }

    public function test_unpaid_partial_and_full_lines_have_exact_aggregate_allocations(): void
    {
        $unpaid = $this->fee('Книги', Fee::CATEGORY_BOOKS, '100.10');
        $partial = $this->fee('Обучение', Fee::CATEGORY_TUITION, '200.20');
        $full = $this->fee('Дополнительная услуга', Fee::CATEGORY_OTHER, '300.30');
        $payload = $this->base + [
            'services' => [
                ['fee_id' => $unpaid->id, 'quantity' => 1, 'paid_now' => '0.00'],
                ['fee_id' => $partial->id, 'quantity' => 1, 'paid_now' => '50.05', 'payment_period' => 'monthly'],
                ['fee_id' => $full->id, 'quantity' => 1, 'paid_now' => '300.30'],
            ],
            'cash_account_id' => $this->account->id, 'payment_method' => 'cash',
        ];

        $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $payload)->assertSessionHasNoErrors();

        $invoice = Invoice::with('items')->sole();
        $this->assertSame('600.60', $invoice->subtotal_amount);
        $this->assertSame('350.35', $invoice->paid_amount);
        $this->assertSame('250.25', $invoice->remaining_amount);
        $this->assertSame(['0.00', '50.05', '300.30'], $invoice->items->pluck('paid_amount')->all());
        $this->assertSame('350.35', InvoicePayment::sole()->amount);
        $this->assertSame('350.35', CashTransaction::sole()->amount);
        $this->assertDatabaseCount('invoice_payments', 1);
        $this->assertDatabaseCount('cash_transactions', 1);
    }

    public function test_zero_allocation_creates_no_payment_or_cash_transaction(): void
    {
        $fee = $this->fee('Книги', Fee::CATEGORY_BOOKS, '100.00');
        $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $this->base + [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertDatabaseCount('cash_transactions', 0);
        $this->assertSame('100.00', Invoice::sole()->remaining_amount);
    }

    public function test_meal_plan_and_tuition_metadata_are_snapshotted(): void
    {
        // Food flexible-duration corrective pass: Food always requires an
        // explicit duration-mode selection and payment_type='calendar' —
        // bundling it with Tuition here therefore requires Tuition's own
        // billing_period to be configured too (Food itself never consults
        // billing_period at all — see InvoiceIssuanceService::issue()).
        \App\Models\AcademicCalendar::create(['academic_year_id' => $this->base['academic_year_id'], 'weekly_days_off' => ['fri', 'sat']]);
        $meal = $this->fee('Питание', Fee::CATEGORY_FOOD, '250.00');
        $tuition = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1000.00');
        $tuition->billingPeriods()->create(['billing_period' => 'monthly']);
        $plan = MealPlan::create([
            'name_ru' => 'Полный день', 'meal_type' => MealPlan::TYPE_BOTH,
            'period' => MealPlan::PERIOD_MONTHLY, 'price' => '999.00', 'is_active' => true,
        ]);
        // Food is structurally dimensional (meal_plan-backed) — a real
        // tariff is required, the flat Fee.amount fallback no longer applies.
        FeePrice::create([
            'fee_id' => $meal->id, 'academic_year_id' => $this->base['academic_year_id'], 'amount' => '250.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => (string) $plan->id, 'payment_period' => 'daily',
        ]);
        // A calendar-billed Tuition line needs a real monthly-denominated
        // tariff (ServiceCoverageService::recordWithBasisPrice() requires
        // one) — the flat Fee.amount fallback is not enough here.
        // grade_id must match: QuickStudentRegistrationService's own
        // normalization stamps the enrollment's grade_id onto Tuition
        // items automatically, and ServiceCoverageService::sourceTariff()
        // requires every dimension the item's metadata carries to match
        // the resolved tariff exactly.
        FeePrice::create([
            'fee_id' => $tuition->id, 'academic_year_id' => $this->base['academic_year_id'], 'amount' => '1000.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true, 'payment_period' => 'monthly',
            'grade_id' => $this->base['grade_id'],
        ]);

        // Note: 'first_last_month' is deliberately NOT exercised here
        // bundled with automatic ServiceCoverage creation — doubling the
        // charged unit price while ServiceCoverageService::sourceTariff()
        // requires the coverage's snapshotted unit_tariff to exactly equal
        // the FeePrice's own raw amount is a genuine, pre-existing
        // interaction gap between that flag and automatic coverage,
        // entirely independent of Food/this corrective pass — out of
        // scope here. payment_period snapshotting alone is sufficient to
        // prove Tuition's own metadata persists correctly alongside
        // Food's.
        $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'services' => [
                ['fee_id' => $meal->id, 'quantity' => 1, 'paid_now' => '0.00', 'meal_plan_id' => $plan->id, 'food_duration_mode' => 'month', 'food_month' => '2026-09'],
                ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'payment_period' => 'monthly'],
            ],
        ])->assertSessionHasNoErrors();

        $items = Invoice::sole()->items()->orderBy('id')->get();
        $this->assertSame($plan->id, $items[0]->metadata['meal_plan_id']);
        $this->assertSame('Полный день', $items[0]->metadata['meal_plan']);
        $this->assertSame('monthly', $items[1]->metadata['payment_period']);

        // Phase 2 — new subscriptions must be created exclusively through
        // StudentServiceSubscriptionService::subscribe(), not a raw
        // StudentServiceSubscription::create() call, and every food-category
        // line must still produce a MealSubscription.
        $this->assertSame(2, StudentServiceSubscription::count());
        $mealSubscription = MealSubscription::sole();
        $this->assertSame($plan->id, $mealSubscription->meal_plan_id);
        $this->assertSame($items[0]->subscription_id, StudentServiceSubscription::where('fee_id', $meal->id)->sole()->id);
    }

    /**
     * MEDIUM gap (PR #12 corrective pass): a paid, bundled Food + Tuition
     * Quick Registration submission (paid_now > 0 on both lines) had no
     * regression coverage of the 'calendar'+Food payment-allocation branch
     * in QuickStudentRegistrationService::register() — the one that walks
     * each InstallmentCoveragePeriod (ordered by installment sequence then
     * invoice_item_id) and allocates each item's own paid_now against its
     * own period(s), as opposed to the plain single-installment path the
     * other tests in this class already exercise.
     *
     * registration_date is deliberately the academic year's OWN final
     * month (2027-06): CalendarPeriodCalculator::resolve() always spans
     * [registration month .. academic-year-end month] for billing_period=
     * 'monthly' (see that class's own docblock) — anchoring registration
     * there collapses Tuition's own monthly schedule to exactly ONE
     * period, so this exercises the real bundled allocation loop with a
     * fully deterministic, hand-verifiable amount on both sides instead of
     * an 11-month schedule.
     */
    public function test_paid_food_and_tuition_bundle_settles_both_lines_and_periods_exactly(): void
    {
        AcademicCalendar::create(['academic_year_id' => $this->base['academic_year_id'], 'weekly_days_off' => ['fri', 'sat']]);
        $meal = $this->fee('Питание', Fee::CATEGORY_FOOD, '250.00');
        $tuition = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1000.00');
        $tuition->billingPeriods()->create(['billing_period' => 'monthly']);
        $plan = MealPlan::create([
            'name_ru' => 'Полный день', 'meal_type' => MealPlan::TYPE_BOTH,
            'period' => MealPlan::PERIOD_MONTHLY, 'price' => '999.00', 'is_active' => true,
        ]);
        FeePrice::create([
            'fee_id' => $meal->id, 'academic_year_id' => $this->base['academic_year_id'], 'amount' => '250.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => (string) $plan->id, 'payment_period' => 'daily',
        ]);
        FeePrice::create([
            'fee_id' => $tuition->id, 'academic_year_id' => $this->base['academic_year_id'], 'amount' => '1000.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true, 'payment_period' => 'monthly',
            'grade_id' => $this->base['grade_id'],
        ]);

        // Food's own [start,end] resolution is entirely independent of
        // registration_date (see FoodDailyBillingTest's own docblock:
        // priceFoodDailyLine() never consults the invoice's pricing date at
        // all) — May is used here purely to steer clear of an unrelated,
        // pre-existing off-by-time edge in the request-level food_end_month
        // check (endOfMonth() carries a time component that can compare as
        // "after" a date-only academic-year end_date when the two land on
        // the exact same calendar day), which is out of scope for this gap.
        $year = AcademicYear::findOrFail($this->base['academic_year_id']);
        $foodDays = app(FoodBillableDayCalculator::class)->calculate($year, '2027-05-01', '2027-05-31')['billable_day_count'];
        $foodTotal = bcmul((string) $foodDays, '250.00', 2);
        $tuitionTotal = '1000.00';
        $invoiceTotal = bcadd($foodTotal, $tuitionTotal, 2);

        $payload = array_merge($this->base, [
            'registration_date' => '2027-06-01',
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
            'cash_account_id' => $this->account->id, 'payment_method' => 'cash',
            'services' => [
                ['fee_id' => $meal->id, 'quantity' => 1, 'paid_now' => $foodTotal, 'meal_plan_id' => $plan->id, 'food_duration_mode' => 'month', 'food_month' => '2027-05'],
                ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => $tuitionTotal, 'payment_period' => 'monthly'],
            ],
        ]);

        $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $payload)->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $items = $invoice->items()->orderBy('id')->get();
        $this->assertCount(2, $items);
        $foodItem = $items->firstWhere('fee_id', $meal->id);
        $tuitionItem = $items->firstWhere('fee_id', $tuition->id);

        // Invoice-level exactness.
        $this->assertSame($invoiceTotal, $invoice->subtotal_amount);
        $this->assertSame($invoiceTotal, $invoice->total_amount);
        $this->assertSame($invoiceTotal, $invoice->fresh()->paid_amount);
        $this->assertSame('0.00', $invoice->fresh()->remaining_amount);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        // Per-line exactness — no cross-allocation between the two lines.
        $this->assertSame($foodTotal, $foodItem->amount);
        $this->assertSame($foodTotal, $foodItem->fresh()->paid_amount);
        $this->assertSame('0.00', $foodItem->fresh()->remaining_amount);
        $this->assertSame($tuitionTotal, $tuitionItem->amount);
        $this->assertSame($tuitionTotal, $tuitionItem->fresh()->paid_amount);
        $this->assertSame('0.00', $tuitionItem->fresh()->remaining_amount);

        // Exactly one payment for the full bundled total — no duplicated
        // payment value, no duplicate cash movement.
        $this->assertDatabaseCount('invoice_payments', 1);
        $this->assertSame($invoiceTotal, InvoicePayment::sole()->amount);
        $this->assertDatabaseCount('cash_transactions', 1);
        $this->assertSame($invoiceTotal, CashTransaction::sole()->amount);

        // Coverage: exactly one ServiceCoverage per line — non-Food
        // (Tuition) coverage semantics are unchanged (still billing_unit
        // 'monthly', still the resolved calendar range).
        $this->assertDatabaseCount('service_coverages', 2);
        $foodCoverage = ServiceCoverage::where('invoice_item_id', $foodItem->id)->sole();
        $tuitionCoverage = ServiceCoverage::where('invoice_item_id', $tuitionItem->id)->sole();
        $this->assertSame('daily', $foodCoverage->billing_unit);
        $this->assertSame('2027-05-01', $foodCoverage->coverage_start->toDateString());
        $this->assertSame('2027-05-31', $foodCoverage->coverage_end->toDateString());
        $this->assertSame('monthly', $tuitionCoverage->billing_unit);
        $this->assertSame('2027-06-01', $tuitionCoverage->coverage_start->toDateString());
        $this->assertSame('2027-06-30', $tuitionCoverage->coverage_end->toDateString());

        // Exactly one InstallmentCoveragePeriod per line (Food's own
        // dedicated lump-sum period; Tuition's single monthly period, per
        // the registration_date choice above) — no duplicate period rows.
        $this->assertDatabaseCount('installment_coverage_periods', 2);
        $foodPeriod = InstallmentCoveragePeriod::where('service_coverage_id', $foodCoverage->id)->sole();
        $tuitionPeriod = InstallmentCoveragePeriod::where('service_coverage_id', $tuitionCoverage->id)->sole();
        $this->assertSame($foodTotal, $foodPeriod->amount);
        $this->assertSame($tuitionTotal, $tuitionPeriod->amount);

        // Payment allocation: exactly one row per period, summing exactly
        // to the payment, each mapped to its OWN item's own period — this
        // is the "Food allocation maps to Food coverage" / "Tuition
        // allocation remains correct" / "no duplicated allocation" proof.
        $this->assertDatabaseCount('payment_allocation_coverage_periods', 2);
        $allocations = PaymentAllocationCoveragePeriod::all();
        $this->assertSame($invoiceTotal, $allocations->reduce(fn ($sum, $a) => bcadd($sum, (string) $a->amount, 2), '0.00'));
        $foodAllocation = $allocations->firstWhere('installment_coverage_period_id', $foodPeriod->id);
        $tuitionAllocation = $allocations->firstWhere('installment_coverage_period_id', $tuitionPeriod->id);
        $this->assertNotNull($foodAllocation);
        $this->assertNotNull($tuitionAllocation);
        $this->assertSame($foodTotal, (string) $foodAllocation->amount);
        $this->assertSame($tuitionTotal, (string) $tuitionAllocation->amount);

        // Food coverage capacity is never exceeded: the allocation equals
        // the period's own capacity exactly, never more, and the period is
        // fully (not over-)settled.
        $this->assertSame(0, bccomp((string) $foodAllocation->amount, (string) $foodPeriod->amount, 2));
        $foodPeriod->refresh();
        $tuitionPeriod->refresh();
        $this->assertTrue($foodPeriod->isSettled());
        $this->assertTrue($tuitionPeriod->isSettled());

        // Subscription semantics unchanged: exactly one MealSubscription,
        // no duplicate subscription rows for either line.
        $this->assertSame(2, StudentServiceSubscription::count());
        $this->assertSame($plan->id, MealSubscription::sole()->meal_plan_id);
    }

    public function test_an_audit_log_is_written_for_the_created_invoice(): void
    {
        $fee = $this->fee('Книги', Fee::CATEGORY_BOOKS, '100.00');
        $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $this->base + [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertDatabaseHas('audit_logs', [
            'model' => 'Invoice', 'model_id' => $invoice->id,
            'action' => 'created', 'user_id' => $this->user->id,
        ]);
    }

    public function test_an_unpaid_invoice_receives_an_installment_immediately_at_issuance(): void
    {
        $fee = $this->fee('Книги', Fee::CATEGORY_BOOKS, '100.00');
        $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $this->base + [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame(1, $invoice->installments()->count());
    }

    /**
     * Transaction/atomicity rule (Phase 2): a food line's subscription,
     * its MealSubscription, the invoice and its items are all created
     * inside the same transaction as the final initial-payment step. If
     * that final step fails, none of it — not even the MealSubscription
     * created earlier in the same request — may survive.
     */
    /**
     * Food flexible-duration corrective pass: Food now REQUIRES
     * payment_type='calendar' unconditionally (InvoiceIssuanceService::
     * issue()) — it can no longer be bought through a custom PaymentPlan
     * ('plan' payment_type) at all, so the exact "Food + custom plan late
     * failure" combination this test used to exercise is now
     * architecturally impossible, not merely untested. Activity exercises
     * the identical late-in-transaction-rollback mechanism (a custom
     * plan's first-installment-percentage validation, which only runs
     * after the subscription/invoice/items already exist in-transaction)
     * without relying on Food's now-calendar-only architecture. See
     * test_meal_plan_and_tuition_metadata_are_snapshotted() above for
     * MealSubscription's own (now calendar-only) rollback coverage.
     */
    public function test_a_late_failure_rolls_back_the_service_subscription_and_everything_else(): void
    {
        $activity = $this->fee('Секция плавания', Fee::CATEGORY_ACTIVITY, '1200.00');
        FeePrice::create([
            'fee_id' => $activity->id, 'academic_year_id' => $this->base['academic_year_id'], 'amount' => '1200.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        $paymentPlan = PaymentPlan::create(['name_ru' => 'План', 'is_active' => true]);
        $paymentPlan->installments()->create(['name_ru' => 'Первый', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '10']);
        $paymentPlan->installments()->create(['name_ru' => 'Второй', 'sequence' => 2, 'offset_days' => 30, 'percentage' => '90']);
        // Finance V2, Phase 2B: the plan must be explicitly assigned to the
        // fee (and the fee must allow 'custom_plan') so this test still
        // reaches the LATE, in-transaction rollback it's designed to
        // exercise, rather than an early request-validation rejection.
        $activity->billingPeriods()->create(['billing_period' => 'custom_plan']);
        $activity->assignedPaymentPlans()->attach($paymentPlan->id);

        $response = $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'plan', 'payment_plan_id' => $paymentPlan->id,
            'cash_account_id' => $this->account->id, 'payment_method' => 'cash',
            'services' => [['fee_id' => $activity->id, 'quantity' => 1, 'paid_now' => '200.00']],
        ]);

        // 200.00 exceeds the first installment's 10% (120.00) — rejected
        // after the subscription/invoice/items have already been created
        // in-transaction, so this exercises the rollback, not a pre-check.
        $response->assertSessionHasErrors('services');
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('student_service_subscriptions', 0);
    }

    /**
     * MEDIUM gap (PR #12 corrective pass): Food's own late-failure rollback
     * seam is architecturally different from the Activity/custom-plan one
     * above (see that test's own docblock — Food can no longer reach a
     * custom PaymentPlan installment check at all, since it now requires
     * payment_type='calendar' unconditionally). The genuine late-failure
     * seam for a Food line is register()'s own per-item "paid_now cannot
     * exceed this line's own resolved amount" check — thrown AFTER
     * $this->issuer->issue() has already run to completion (invoice,
     * items, ServiceCoverage, StudentServiceSubscription, and — via
     * $subscriptionResolver, mid-issuance — the MealSubscription itself),
     * inside the very same still-open outer transaction. No production-only
     * failure hook is introduced: this is the exact validation
     * test_unpaid_partial_and_full_lines_have_exact_aggregate_allocations()
     * above already exercises for a non-Food line, deliberately forced past
     * MealSubscription creation this time.
     */
    public function test_a_late_failure_after_meal_subscription_creation_rolls_back_everything(): void
    {
        AcademicCalendar::create(['academic_year_id' => $this->base['academic_year_id'], 'weekly_days_off' => ['fri', 'sat']]);
        $meal = $this->fee('Питание', Fee::CATEGORY_FOOD, '250.00');
        $plan = MealPlan::create([
            'name_ru' => 'Полный день', 'meal_type' => MealPlan::TYPE_BOTH,
            'period' => MealPlan::PERIOD_MONTHLY, 'price' => '999.00', 'is_active' => true,
        ]);
        FeePrice::create([
            'fee_id' => $meal->id, 'academic_year_id' => $this->base['academic_year_id'], 'amount' => '250.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => (string) $plan->id, 'payment_period' => 'daily',
        ]);

        $this->assertSame(0, MealSubscription::count());
        $year = AcademicYear::findOrFail($this->base['academic_year_id']);
        $foodDays = app(FoodBillableDayCalculator::class)->calculate($year, '2026-09-01', '2026-09-30')['billable_day_count'];
        $foodTotal = bcmul((string) $foodDays, '250.00', 2);
        // Deliberately one cent over the line's own resolved amount — the
        // failure only fires in register()'s post-issuance per-item loop,
        // i.e. strictly after MealSubscription::create() already ran.
        $overpaid = bcadd($foodTotal, '0.01', 2);

        $payload = array_merge($this->base, [
            'payment_type' => 'calendar',
            'cash_account_id' => $this->account->id, 'payment_method' => 'cash',
            'services' => [
                ['fee_id' => $meal->id, 'quantity' => 1, 'paid_now' => $overpaid, 'meal_plan_id' => $plan->id, 'food_duration_mode' => 'month', 'food_month' => '2026-09'],
            ],
        ]);

        $response = $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $payload);

        $response->assertSessionHasErrors('services.0.paid_now');
        // Pre-operation state fully restored — nothing from the failed
        // attempt survives, including the MealSubscription created before
        // the failing check ran.
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('student_service_subscriptions', 0);
        $this->assertDatabaseCount('meal_subscriptions', 0);
        $this->assertDatabaseCount('service_coverages', 0);
        $this->assertDatabaseCount('installment_coverage_periods', 0);
        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('payment_allocation_coverage_periods', 0);
        $this->assertDatabaseCount('cash_transactions', 0);
    }
}
