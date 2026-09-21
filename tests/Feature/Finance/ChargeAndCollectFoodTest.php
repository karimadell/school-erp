<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicCalendar;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\ServiceCoverage;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Services\Finance\InvoiceCancellationService;
use App\Services\Finance\InvoicePaymentService;
use Illuminate\Support\Str;

/**
 * Existing-student Food purchase corrective pass.
 *
 * An already-registered student may buy Food again (a day, a school week, a
 * custom range, an extension after a previously paid period) through
 * Finance → student → Charge & Collect, WITHOUT a second Student or
 * Enrollment, reusing the exact same FoodBillableDayCalculator/
 * InvoiceCalculationService/InvoiceIssuanceService/ServiceCoverage machinery
 * Quick Registration's own Food purchase already proves (see
 * FoodDailyBillingTest) — never a second pricing engine.
 */
class ChargeAndCollectFoodTest extends FinanceOperationsTestCase
{
    private AcademicCalendar $calendar;

    private MealPlan $mealPlan;

    private Fee $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCanonicalRegistrationModeCatalog();
        $this->calendar = AcademicCalendar::create([
            'academic_year_id' => $this->year->id,
            'weekly_days_off' => ['fri', 'sat'],
        ]);
        $this->mealPlan = MealPlan::create([
            'name_ru' => 'Полный рацион', 'meal_type' => 'both', 'period' => 'daily',
            'price' => '100.00', 'is_active' => true,
        ]);
        $this->food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id,
            'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => (string) $this->mealPlan->id,
            'amount' => '100.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'academic_year_id' => $this->year->id,
            'fee_id' => $this->food->id,
            'meal_plan_id' => $this->mealPlan->id,
            'food_duration_mode' => 'day',
            'food_date' => '2026-09-01', // Tuesday — a teaching day.
            'due_date' => '2027-01-01',
            'pricing_date' => '2026-09-01',
            'idempotency_key' => (string) Str::uuid(),
            'collect_amount' => '0',
        ], $overrides);
    }

    private function charge(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->accountant)
            ->post(route('dashboard.students.charge.store', $this->student), $this->payload($overrides));
    }

    // ----- A. One-day purchase --------------------------------------------

    public function test_one_day_food_purchase_for_existing_student(): void
    {
        $response = $this->charge();

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Student::count());
        $invoice = Invoice::sole();
        $item = $invoice->items()->sole();

        $this->assertSame($this->student->id, $invoice->student_id);
        $this->assertSame('100.00', $invoice->total_amount);
        $this->assertSame(Fee::CATEGORY_FOOD, $item->fee->category);
        $this->assertSame(1, $item->metadata['food_billable_day_count']);
        $this->assertSame('2026-09-01', $item->metadata['food_coverage_start']);
        $this->assertSame('2026-09-01', $item->metadata['food_coverage_end']);

        $coverage = ServiceCoverage::sole();
        $this->assertSame('2026-09-01', $coverage->coverage_start->toDateString());
        $this->assertSame('2026-09-01', $coverage->coverage_end->toDateString());
        $this->assertSame($this->student->id, $coverage->student_id);

        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
    }

    public function test_one_day_food_purchase_with_full_payment(): void
    {
        $response = $this->charge([
            'collect_amount' => '100.00',
            'payment_method' => 'cash',
            'cash_account_id' => $this->cash->id,
        ]);

        $response->assertSessionHasNoErrors();
        $invoice = Invoice::sole();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('100.00', $invoice->paid_amount);
        $this->assertSame('0.00', $invoice->remaining_amount);
        $payment = InvoicePayment::sole();
        $this->assertSame($invoice->id, $payment->invoice_id);
    }

    // ----- B. School-week purchase ------------------------------------------

    public function test_school_week_food_purchase(): void
    {
        // Sun 2026-08-30 .. Sat 2026-09-05: Sun/Mon/Tue/Wed/Thu teaching (5),
        // Fri/Sat off (weekly_days_off) — 5 billable days.
        $response = $this->charge([
            'food_duration_mode' => 'school_week',
            'food_week_start' => '2026-08-30',
            'food_date' => null,
        ]);

        $response->assertSessionHasNoErrors();
        $invoice = Invoice::sole();
        $this->assertSame('500.00', $invoice->total_amount);
        $item = $invoice->items()->sole();
        $this->assertSame(5, $item->metadata['food_billable_day_count']);
        $this->assertSame('2026-08-30', $item->metadata['food_coverage_start']);
        $this->assertSame('2026-09-05', $item->metadata['food_coverage_end']);
    }

    // ----- C. Custom-range purchase ------------------------------------------

    public function test_custom_range_food_purchase_excludes_non_teaching_days(): void
    {
        // 2026-09-01 (Tue) .. 2026-09-10 (Thu): excludes Fri 09-04/Sat 09-05
        // — 8 billable days.
        $response = $this->charge([
            'food_duration_mode' => 'custom_range',
            'food_date' => null,
            'food_range_start' => '2026-09-01',
            'food_range_end' => '2026-09-10',
        ]);

        $response->assertSessionHasNoErrors();
        $invoice = Invoice::sole();
        $item = $invoice->items()->sole();
        $this->assertSame(8, $item->metadata['food_billable_day_count']);
        $this->assertSame('800.00', $invoice->total_amount);

        // Server preview (the SAME price() endpoint the blade calls) must
        // agree exactly with what was actually issued.
        $preview = $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $this->food->id,
            'quantity' => 1,
            'academic_year_id' => $this->year->id,
            'enrollment_mode_id' => $this->enrollment->enrollment_mode_id,
            'meal_plan_id' => $this->mealPlan->id,
            'food_duration_mode' => 'custom_range',
            'food_range_start' => '2026-09-01',
            'food_range_end' => '2026-09-10',
        ])->json();

        $this->assertSame(8, $preview['billable_day_count']);
        $this->assertSame('800.00', $preview['amount']);
        $this->assertSame($item->metadata['food_coverage_start'], $preview['coverage_start']);
        $this->assertSame($item->metadata['food_coverage_end'], $preview['coverage_end']);
    }

    // ----- D. Non-overlapping extension --------------------------------------

    public function test_extending_food_with_a_new_non_overlapping_range_creates_a_second_invoice(): void
    {
        $first = $this->charge([
            'collect_amount' => '100.00', 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
        ]);
        $first->assertSessionHasNoErrors();
        $firstInvoice = Invoice::sole();
        $firstCoverage = ServiceCoverage::sole();

        $second = $this->charge([
            'food_duration_mode' => 'custom_range',
            'food_date' => null,
            'food_range_start' => '2026-09-15',
            'food_range_end' => '2026-09-17',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $second->assertSessionHasNoErrors();

        $this->assertSame(1, Student::count());
        $this->assertSame($this->student->id, Student::sole()->id);
        $this->assertSame(1, Enrollment::where('student_id', $this->student->id)->count());
        $this->assertSame(2, Invoice::count());
        $this->assertSame(2, ServiceCoverage::count());

        // The original invoice/coverage are untouched.
        $this->assertSame('100.00', $firstInvoice->fresh()->total_amount);
        $this->assertSame(Invoice::STATUS_PAID, $firstInvoice->fresh()->status);
        $this->assertSame('2026-09-01', $firstCoverage->fresh()->coverage_start->toDateString());
        $this->assertSame('2026-09-01', $firstCoverage->fresh()->coverage_end->toDateString());

        $secondInvoice = Invoice::where('id', '!=', $firstInvoice->id)->sole();
        $this->assertSame($this->student->id, $secondInvoice->student_id);
        // 2026-09-15 (Tue), 09-16 (Wed), 09-17 (Thu) — 3 billable days.
        $this->assertSame('300.00', $secondInvoice->total_amount);
    }

    public function test_second_food_purchase_reuses_the_same_subscription_and_meal_subscription(): void
    {
        $this->charge()->assertSessionHasNoErrors();
        $this->assertSame(1, StudentServiceSubscription::count());
        $this->assertSame(1, MealSubscription::count());

        $this->charge([
            'food_duration_mode' => 'custom_range',
            'food_date' => null,
            'food_range_start' => '2026-09-15',
            'food_range_end' => '2026-09-17',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        // No second subscription/meal-subscription row for the same
        // enrollment+fee — InvoiceIssuanceService reuses the already-active
        // subscription automatically.
        $this->assertSame(1, StudentServiceSubscription::count());
        $this->assertSame(1, MealSubscription::count());
        $this->assertSame(2, Invoice::count());
    }

    // ----- D-bis. Same-day, different MealPlans are independent purchases ----
    // Stolovaya P1 corrective: guardAgainstOverlappingFoodCoverage() now
    // scopes by fee + MealPlan (via the ServiceCoverage.option_value the
    // pricing pipeline already stamps), not fee alone — so a student may
    // buy several different meals the same day through this SAME existing
    // Add Service Food entry point (chargeStore()), not just through the
    // new Stolovaya screen. Genuine duplicates (same MealPlan, same day)
    // must still be rejected — proven right after.

    public function test_different_meal_plans_on_the_same_day_are_both_allowed(): void
    {
        $drink = MealPlan::create([
            'name_ru' => 'Напиток', 'meal_type' => 'both', 'period' => 'daily',
            'price' => '999.00', 'is_active' => true,
        ]);
        FeePrice::create([
            'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id,
            'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => (string) $drink->id,
            'amount' => '10.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);

        $this->charge()->assertSessionHasNoErrors(); // Полный рацион, 2026-09-01, 100.00

        $second = $this->charge([
            'meal_plan_id' => $drink->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $second->assertSessionHasNoErrors();

        $this->assertSame(2, Invoice::count());
        $this->assertSame(2, ServiceCoverage::count());
        $this->assertSame(['10.00', '100.00'], Invoice::query()->orderBy('total_amount')->pluck('total_amount')->all());
    }

    public function test_same_meal_plan_twice_on_the_same_day_is_still_rejected_and_names_the_meal(): void
    {
        $this->charge()->assertSessionHasNoErrors(); // Полный рацион

        $response = $this->charge(['idempotency_key' => (string) Str::uuid()]);

        $response->assertSessionHasErrors('fee_id');
        $this->assertStringContainsString('Полный рацион', session('errors')->first('fee_id'));
        $this->assertSame(1, Invoice::count());
    }

    // ----- E. Overlapping range is rejected safely ---------------------------

    public function test_overlapping_food_range_is_rejected_without_partial_persistence(): void
    {
        $this->charge()->assertSessionHasNoErrors(); // covers 2026-09-01

        $response = $this->charge([
            'food_duration_mode' => 'custom_range',
            'food_date' => null,
            'food_range_start' => '2026-08-30',
            'food_range_end' => '2026-09-02', // overlaps 09-01
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('fee_id');
        $response->assertSessionHas('existing_invoice_id');

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, ServiceCoverage::count());
        $this->assertSame(0, InvoicePayment::count());
    }

    public function test_identical_repeat_range_is_rejected_and_points_to_the_existing_invoice(): void
    {
        $this->charge([
            'collect_amount' => '40.00', 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
        ])->assertSessionHasNoErrors();
        $existing = Invoice::sole();

        $response = $this->charge(['idempotency_key' => (string) Str::uuid()]);

        $response->assertSessionHasErrors('fee_id');
        $response->assertSessionHas('existing_invoice_id', $existing->id);
        $this->assertSame(1, Invoice::count());
    }

    public function test_voided_food_invoice_does_not_block_a_repurchase_for_the_same_dates(): void
    {
        $this->charge()->assertSessionHasNoErrors();
        $voided = Invoice::sole();
        app(InvoiceCancellationService::class)->void($voided, 'Ошибка оформления', $this->accountant);

        $response = $this->charge(['idempotency_key' => (string) Str::uuid()]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Invoice::count());
    }

    // ----- F. Partial payment then later settlement --------------------------

    public function test_partial_payment_then_later_payment_settles_the_same_invoice(): void
    {
        $response = $this->charge([
            'food_duration_mode' => 'school_week',
            'food_week_start' => '2026-08-30',
            'food_date' => null,
            'collect_amount' => '200.00',
            'payment_method' => 'cash',
            'cash_account_id' => $this->cash->id,
        ]);
        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame('500.00', $invoice->total_amount);
        $this->assertSame('200.00', $invoice->paid_amount);
        $this->assertSame('300.00', $invoice->remaining_amount);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);

        // A same-fee, same-dates second Charge & Collect attempt is refused
        // (overlap guard) and points back at this same invoice — the
        // cashier settles the remainder against it directly, never a
        // second/replacement invoice.
        $second = $this->charge([
            'food_duration_mode' => 'school_week',
            'food_week_start' => '2026-08-30',
            'food_date' => null,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $second->assertSessionHasErrors('fee_id');
        $second->assertSessionHas('existing_invoice_id', $invoice->id);
        $this->assertSame(1, Invoice::count());

        // The remainder is settled against the SAME invoice via the
        // canonical payment path.
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            amount: '300.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $this->assertSame(1, Invoice::count());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('0.00', $invoice->fresh()->remaining_amount);
        $this->assertSame(1, Student::count());
    }

    // ----- G. Idempotent double submit ---------------------------------------

    public function test_double_submit_with_the_same_idempotency_key_creates_exactly_one_invoice(): void
    {
        $key = (string) Str::uuid();

        $first = $this->charge([
            'idempotency_key' => $key, 'collect_amount' => '100.00',
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
        ]);
        $first->assertSessionHasNoErrors();

        $second = $this->charge([
            'idempotency_key' => $key, 'collect_amount' => '100.00',
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
        ]);
        $second->assertSessionHasNoErrors();

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, InvoicePayment::count());
        $this->assertSame(1, Student::count());
        $this->assertSame('100.00', $this->money($this->cash->fresh()->balance));
    }

    // ----- Validation ---------------------------------------------------------

    public function test_food_requires_meal_plan_and_duration_fields(): void
    {
        $response = $this->charge([
            'meal_plan_id' => null,
            'food_duration_mode' => null,
            'food_date' => null,
        ]);

        $response->assertSessionHasErrors(['meal_plan_id', 'food_duration_mode']);
        $this->assertSame(0, Invoice::count());
    }

    private function money(mixed $value): string
    {
        return bcadd((string) $value, '0', 2);
    }
}
