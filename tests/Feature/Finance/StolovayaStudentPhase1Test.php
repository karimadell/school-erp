<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicCalendar;
use App\Models\CashTransaction;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\MealPlan;
use App\Models\RevenueEntry;
use App\Models\ServiceCoverage;
use App\Models\Student;
use Illuminate\Support\Str;

/**
 * Столовая (Student, Phase 1) — a dedicated daily-meal operational entry
 * point over the EXISTING Student Food accounting path
 * (ChargeAndCollectService). This never touches RevenueEntry/RevenueCategory
 * (buffet/school_food/cafeteria are unrelated to this flow) and never
 * reimplements pricing/coverage/payment logic — see StolovayaController's
 * own docblock.
 */
class StolovayaStudentPhase1Test extends FinanceOperationsTestCase
{
    private AcademicCalendar $calendar;

    private MealPlan $mealPlan;

    private MealPlan $secondMealPlan;

    private MealPlan $unpricedMealPlan;

    private Fee $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar = AcademicCalendar::create([
            'academic_year_id' => $this->year->id,
            'weekly_days_off' => ['fri', 'sat'],
        ]);

        // MealPlan.price is deliberately WRONG (999.00) — proof #4 below
        // asserts the charged/previewed amount comes from FeePrice, never
        // from this column.
        $this->mealPlan = MealPlan::create([
            'name_ru' => 'Комплексное питание', 'meal_type' => 'both', 'period' => 'daily',
            'price' => '999.00', 'is_active' => true,
        ]);
        $this->secondMealPlan = MealPlan::create([
            'name_ru' => 'Завтрак', 'meal_type' => 'breakfast', 'period' => 'daily',
            'price' => '999.00', 'is_active' => true,
        ]);
        // Active MealPlan with no daily FeePrice at all — must never appear
        // in the Stolovaya meal dropdown (proof #3).
        $this->unpricedMealPlan = MealPlan::create([
            'name_ru' => 'Без цены', 'meal_type' => 'lunch', 'period' => 'daily',
            'price' => '50.00', 'is_active' => true,
        ]);

        $this->food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id,
            'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => (string) $this->mealPlan->id,
            'amount' => '250.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        FeePrice::create([
            'fee_id' => $this->food->id, 'academic_year_id' => $this->year->id,
            'payment_period' => 'daily', 'option_type' => 'meal_plan', 'option_value' => (string) $this->secondMealPlan->id,
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
            'meal_plan_id' => $this->mealPlan->id,
            'food_date' => '2026-09-01', // Tuesday — a teaching day.
            'due_date' => '2026-09-01',
            'pricing_date' => '2026-09-01',
            'idempotency_key' => (string) Str::uuid(),
            'collect_amount' => '0',
        ], $overrides);
    }

    private function charge(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->accountant)
            ->post(route('dashboard.students.stolovaya.store', $this->student), $this->payload($overrides));
    }

    // 1. Stolovaya card appears for an authorized Finance user.
    public function test_stolovaya_card_appears_on_income_landing_page_for_authorized_user(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.index'));

        $response->assertOk();
        $response->assertSee(__('finance_workspace.income_type_stolovaya'));
        $response->assertSee(route('dashboard.finance.income.stolovaya'), false);
    }

    // 2. Dedicated Student Stolovaya page opens.
    public function test_dedicated_student_stolovaya_page_opens(): void
    {
        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.students.stolovaya.create', $this->student));

        $response->assertOk();
        $response->assertSee(__('finance_workspace.stolovaya_page_title'));
        $response->assertSee($this->student->full_name);
    }

    // Income landing "Столовая" redirects into the existing, unmodified
    // student search screen — no new search UI was built.
    public function test_income_stolovaya_redirects_to_existing_student_search_screen(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.stolovaya'));

        $response->assertRedirect(route('dashboard.finance.income.students'));
    }

    // 3. Only canonical priced Food MealPlans are offered.
    public function test_only_priced_meal_plans_are_offered(): void
    {
        $response = $this->actingAs($this->accountant)
            ->get(route('dashboard.students.stolovaya.create', $this->student));

        $response->assertOk();
        $response->assertSee('Комплексное питание');
        $response->assertSee('Завтрак');
        $response->assertDontSee('Без цены');
    }

    // 4. Price is resolved from FeePrice, not MealPlan.price.
    public function test_price_is_resolved_from_fee_price_not_meal_plan_price(): void
    {
        $response = $this->charge();

        $response->assertSessionHasNoErrors();
        $invoice = Invoice::sole();
        // FeePrice says 250.00; MealPlan::price (999.00) must never surface.
        $this->assertSame('250.00', $invoice->total_amount);
    }

    // 5. Single-day meal date is honored.
    public function test_single_day_meal_date_is_honored(): void
    {
        $this->charge();

        $item = Invoice::sole()->items()->sole();
        $this->assertSame(1, $item->metadata['food_billable_day_count']);
        $this->assertSame('2026-09-01', $item->metadata['food_coverage_start']);
        $this->assertSame('2026-09-01', $item->metadata['food_coverage_end']);

        $coverage = ServiceCoverage::sole();
        $this->assertSame('2026-09-01', $coverage->coverage_start->toDateString());
        $this->assertSame('2026-09-01', $coverage->coverage_end->toDateString());
    }

    // 6. Paid-now Student meal.
    public function test_paid_now_student_meal_creates_correct_invoice_payment_and_cash_transaction(): void
    {
        $response = $this->charge([
            'collect_amount' => '250.00',
            'payment_method' => 'cash',
            'cash_account_id' => $this->cash->id,
        ]);

        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('250.00', $invoice->paid_amount);
        $this->assertSame('0.00', $invoice->remaining_amount);
        $this->assertSame($this->student->id, $invoice->student_id);
        $this->assertSame(1, $invoice->items()->count());

        $payment = InvoicePayment::sole();
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame('250.00', $payment->amount);

        $transactions = CashTransaction::query()->where('invoice_payment_id', $payment->id)->get();
        $this->assertCount(1, $transactions);
        $this->assertSame('250.00', (string) $transactions->first()->amount);
        $this->assertSame($this->cash->id, $transactions->first()->cash_account_id);

        $this->assertSame(0, RevenueEntry::count());
    }

    // 7. Unpaid Student meal.
    public function test_unpaid_student_meal_creates_invoice_and_remains_debt(): void
    {
        $response = $this->charge(['collect_amount' => '0']);

        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame('250.00', $invoice->remaining_amount);
        $this->assertSame('0.00', $invoice->paid_amount);

        $this->assertSame(0, InvoicePayment::count());
        $this->assertSame(0, CashTransaction::count());
        $this->assertSame(0, RevenueEntry::count());
    }

    // 8. Duplicate/idempotent resubmission does not double-charge.
    public function test_duplicate_submission_with_same_idempotency_key_does_not_double_charge(): void
    {
        $key = (string) Str::uuid();

        $this->charge([
            'idempotency_key' => $key, 'collect_amount' => '250.00',
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
        ])->assertSessionHasNoErrors();

        $this->charge([
            'idempotency_key' => $key, 'collect_amount' => '250.00',
            'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, InvoicePayment::count());
        $this->assertSame('250.00', $this->money($this->cash->fresh()->balance));
    }

    // 9. Existing Food overlap guard remains effective for a genuine
    // duplicate (same student + same MealPlan + same date), and the
    // rejection message names the specific meal — never implies an
    // unrelated meal conflicted (Stolovaya P1 corrective).
    public function test_overlapping_same_day_purchase_is_rejected(): void
    {
        $this->charge()->assertSessionHasNoErrors(); // Комплексное питание

        $response = $this->charge(['idempotency_key' => (string) Str::uuid()]);

        $response->assertSessionHasErrors('fee_id');
        $response->assertSessionHas('existing_invoice_id');
        $this->assertStringContainsString('Комплексное питание', session('errors')->first('fee_id'));
        $this->assertSame(1, Invoice::count());
    }

    // Stolovaya P1 corrective (owner-approved business rule): a student may
    // buy several DIFFERENT meal plans the same day — the overlap guard is
    // scoped by MealPlan (fee + option_value + date), not by fee alone.
    public function test_different_meal_plans_on_the_same_day_are_both_allowed(): void
    {
        $this->charge()->assertSessionHasNoErrors(); // Комплексное питание, 250.00

        $second = $this->charge([
            'meal_plan_id' => $this->secondMealPlan->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $second->assertSessionHasNoErrors();
        $this->assertSame(2, Invoice::count());
        $this->assertSame(2, ServiceCoverage::count());
        $this->assertSame(['100.00', '250.00'], Invoice::query()->orderBy('total_amount')->pluck('total_amount')->all());
    }

    // Same MealPlan, a different date, is a legitimate separate purchase —
    // unaffected by the P1 corrective (the date-range check is untouched).
    public function test_same_meal_plan_on_a_different_date_is_allowed(): void
    {
        $this->charge()->assertSessionHasNoErrors(); // 2026-09-01

        $second = $this->charge([
            'food_date' => '2026-09-02',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $second->assertSessionHasNoErrors();
        $this->assertSame(2, Invoice::count());
        $this->assertSame(2, ServiceCoverage::count());
    }

    // 10. Buffet behavior remains unchanged — this feature touches neither
    // RevenueEntryController nor RevenueCategorySeeder, so Buffet's own
    // dedicated suite (FinanceFoodBuffetCategorySeparationTest) is
    // untouched by these changes; spot-check the shared MealPlan::sellableFood()
    // refactor didn't leak into Buffet's screen.
    public function test_buffet_screen_is_unaffected_by_stolovaya_changes(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.income.buffet'));
        $response->assertRedirect(route('dashboard.finance.income.revenue.create', ['type' => 'buffet']));
    }

    // 11. school_food remains unrelated to the Student flow.
    public function test_school_food_category_is_never_referenced(): void
    {
        $this->charge([
            'collect_amount' => '250.00', 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, RevenueEntry::count());
        $this->assertSame(0, \App\Models\RevenueCategory::count());
    }

    // 12. Unauthorized financial roles cannot use the route. Mirrors
    // FinanceFoodBuffetCategorySeparationTest::test_unauthorized_user_cannot_reach_buffet_create_form
    // exactly: 'reception' holds 'view invoices' but not 'manage invoices'
    // — the same gate this feature reuses — so it is the correct role to
    // prove the boundary with (a bare 'teacher' account is redirected
    // earlier still, by the app-wide EnsureAdministrativePortalAccess
    // middleware, before any Finance permission is even evaluated —
    // pre-existing, unrelated to this feature, and an even stronger
    // exclusion than the one being tested here).
    public function test_unauthorized_role_cannot_reach_stolovaya_create_form(): void
    {
        $reception = $this->user('reception');

        $this->actingAs($reception)
            ->get(route('dashboard.students.stolovaya.create', $this->student))
            ->assertForbidden();
    }

    public function test_unauthorized_role_cannot_submit_stolovaya_charge(): void
    {
        $reception = $this->user('reception');

        $this->actingAs($reception)
            ->post(route('dashboard.students.stolovaya.store', $this->student), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, Invoice::count());
    }

    private function money(mixed $value): string
    {
        return bcadd((string) $value, '0', 2);
    }
}
