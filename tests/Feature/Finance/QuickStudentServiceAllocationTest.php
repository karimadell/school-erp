<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\PaymentPlan;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\StudentServiceSubscription;
use App\Models\User;
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
        $meal = $this->fee('Питание', Fee::CATEGORY_FOOD, '250.00');
        $tuition = $this->fee('Обучение', Fee::CATEGORY_TUITION, '1000.00');
        $plan = MealPlan::create([
            'name_ru' => 'Полный день', 'meal_type' => MealPlan::TYPE_BOTH,
            'period' => MealPlan::PERIOD_MONTHLY, 'price' => '999.00', 'is_active' => true,
        ]);
        // Food is structurally dimensional (meal_plan-backed) — a real
        // tariff is required, the flat Fee.amount fallback no longer applies.
        FeePrice::create([
            'fee_id' => $meal->id, 'academic_year_id' => $this->base['academic_year_id'], 'amount' => '250.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => (string) $plan->id,
        ]);

        $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $this->base + [
            'services' => [
                ['fee_id' => $meal->id, 'quantity' => 1, 'paid_now' => '0.00', 'meal_plan_id' => $plan->id],
                ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'payment_period' => 'monthly', 'first_last_month' => true],
            ],
        ])->assertSessionHasNoErrors();

        $items = Invoice::sole()->items()->orderBy('id')->get();
        $this->assertSame($plan->id, $items[0]->metadata['meal_plan_id']);
        $this->assertSame('Полный день', $items[0]->metadata['meal_plan']);
        $this->assertSame('monthly', $items[1]->metadata['payment_period']);
        $this->assertTrue($items[1]->metadata['first_last_month']);

        // Phase 2 — new subscriptions must be created exclusively through
        // StudentServiceSubscriptionService::subscribe(), not a raw
        // StudentServiceSubscription::create() call, and every food-category
        // line must still produce a MealSubscription.
        $this->assertSame(2, StudentServiceSubscription::count());
        $mealSubscription = MealSubscription::sole();
        $this->assertSame($plan->id, $mealSubscription->meal_plan_id);
        $this->assertSame($items[0]->subscription_id, StudentServiceSubscription::where('fee_id', $meal->id)->sole()->id);
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
    public function test_a_late_failure_rolls_back_the_meal_subscription_and_everything_else(): void
    {
        $meal = $this->fee('Питание', Fee::CATEGORY_FOOD, '1200.00');
        $plan = MealPlan::create(['name_ru' => 'Полный день', 'meal_type' => MealPlan::TYPE_BOTH, 'period' => MealPlan::PERIOD_MONTHLY, 'price' => '1200.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $meal->id, 'academic_year_id' => $this->base['academic_year_id'], 'amount' => '1200.00', 'currency' => 'EGP',
            'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => (string) $plan->id,
        ]);
        $paymentPlan = PaymentPlan::create(['name_ru' => 'План', 'is_active' => true]);
        $paymentPlan->installments()->create(['name_ru' => 'Первый', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '10']);
        $paymentPlan->installments()->create(['name_ru' => 'Второй', 'sequence' => 2, 'offset_days' => 30, 'percentage' => '90']);
        // Finance V2, Phase 2B: the plan must be explicitly assigned to the
        // fee (and the fee must allow 'custom_plan') so this test still
        // reaches the LATE, in-transaction rollback it's designed to
        // exercise, rather than an early request-validation rejection.
        $meal->billingPeriods()->create(['billing_period' => 'custom_plan']);
        $meal->assignedPaymentPlans()->attach($paymentPlan->id);

        $response = $this->actingAs($this->user)->post(route('dashboard.quick-registration.store'), $this->base + [
            'payment_type' => 'plan', 'payment_plan_id' => $paymentPlan->id,
            'cash_account_id' => $this->account->id, 'payment_method' => 'cash',
            'services' => [['fee_id' => $meal->id, 'quantity' => 1, 'paid_now' => '200.00', 'meal_plan_id' => $plan->id]],
        ]);

        // 200.00 exceeds the first installment's 10% (120.00) — rejected
        // after the subscription/invoice/items have already been created
        // in-transaction, so this exercises the rollback, not a pre-check.
        $response->assertSessionHasErrors('services');
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('student_service_subscriptions', 0);
        $this->assertDatabaseCount('meal_subscriptions', 0);
    }
}
