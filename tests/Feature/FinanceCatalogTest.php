<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\UniformBundleComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCashAccount(): CashAccount
    {
        return CashAccount::create(['name' => 'Main Cashbox', 'type' => CashAccount::TYPE_CASH]);
    }

    protected function makeEnrollment(): Enrollment
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A', 'name_ar' => 'الفصل أ']);
        $student = Student::create(['name' => 'Test Student']);
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        return Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'stage_id' => $stage->id,
            'grade_id' => $grade->id,
            'class_id' => $class->id,
            'status' => 'active',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Policy 1: uniform bundles are independently priced
    |--------------------------------------------------------------------------
    */

    public function test_a_uniform_bundle_and_its_components_are_both_fees_with_independent_prices(): void
    {
        $shirt = Fee::create(['name_ru' => 'Рубашка', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => 300, 'is_active' => true]);
        $trousers = Fee::create(['name_ru' => 'Брюки', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => 400, 'is_active' => true]);
        $bundle = Fee::create(['name_ru' => 'Полный комплект формы', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => 600, 'is_active' => true]);

        UniformBundleComponent::create(['bundle_fee_id' => $bundle->id, 'item_fee_id' => $shirt->id, 'quantity' => 1]);
        UniformBundleComponent::create(['bundle_fee_id' => $bundle->id, 'item_fee_id' => $trousers->id, 'quantity' => 1]);

        // The bundle's own price (600) is independent of — and here,
        // deliberately cheaper than — the sum of its components (700).
        $this->assertSame(600.0, $bundle->currentPrice());
        $this->assertCount(2, $bundle->bundleComponents);
        $this->assertTrue($shirt->partOfBundles->contains(fn ($c) => $c->bundle_fee_id === $bundle->id));
    }

    public function test_an_individual_uniform_item_can_be_sold_separately_from_any_bundle(): void
    {
        $enrollment = $this->makeEnrollment();
        $shirt = Fee::create(['name_ru' => 'Рубашка', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => 300, 'is_active' => true]);

        $subscription = (new \App\Services\StudentServiceSubscriptionService())->subscribe($enrollment, $shirt);

        $this->assertDatabaseHas('student_service_subscriptions', [
            'id' => $subscription->id,
            'fee_id' => $shirt->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Meal plans / meal subscriptions
    |--------------------------------------------------------------------------
    */

    public function test_a_meal_plan_can_be_created_on_the_already_existing_table(): void
    {
        $plan = MealPlan::create([
            'name_ru' => 'Завтрак и обед',
            'meal_type' => MealPlan::TYPE_BOTH,
            'period' => MealPlan::PERIOD_MONTHLY,
            'price' => 1500,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('meal_plans', ['id' => $plan->id, 'meal_type' => 'both']);
    }

    public function test_a_meal_subscription_links_an_enrollment_to_a_meal_plan_with_dates(): void
    {
        $enrollment = $this->makeEnrollment();
        $plan = MealPlan::create([
            'name_ru' => 'Обед', 'meal_type' => MealPlan::TYPE_LUNCH, 'period' => MealPlan::PERIOD_DAILY, 'price' => 100,
        ]);

        $subscription = MealSubscription::create([
            'enrollment_id' => $enrollment->id,
            'meal_plan_id' => $plan->id,
            'start_date' => '2026-09-01',
        ]);

        $this->assertTrue($subscription->isActiveOn('2026-10-01'));
        $this->assertSame($plan->id, $enrollment->mealSubscriptions()->first()->meal_plan_id);
    }

    public function test_a_meal_subscription_stopped_mid_year_is_no_longer_active_after_its_end_date(): void
    {
        $enrollment = $this->makeEnrollment();
        $plan = MealPlan::create([
            'name_ru' => 'Обед', 'meal_type' => MealPlan::TYPE_LUNCH, 'period' => MealPlan::PERIOD_DAILY, 'price' => 100,
        ]);

        $subscription = MealSubscription::create([
            'enrollment_id' => $enrollment->id,
            'meal_plan_id' => $plan->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-11-30',
        ]);

        $this->assertTrue($subscription->isActiveOn('2026-10-15'));
        $this->assertFalse($subscription->isActiveOn('2026-12-01'));
    }

    /*
    |--------------------------------------------------------------------------
    | Invoice/InvoiceItem extensions
    |--------------------------------------------------------------------------
    */

    public function test_invoice_persists_academic_year_and_due_date(): void
    {
        $enrollment = $this->makeEnrollment();

        $invoice = Invoice::create([
            'student_id' => $enrollment->student_id,
            'cash_account_id' => $this->makeCashAccount()->id,
            'academic_year_id' => $enrollment->academic_year_id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'status' => Invoice::STATUS_UNPAID,
            'due_date' => '2026-12-01',
        ]);

        $this->assertTrue($invoice->fresh()->academicYear->is($enrollment->academicYear));
        $this->assertSame('2026-12-01', $invoice->fresh()->due_date->toDateString());
    }

    public function test_invoice_overdue_scope_only_matches_outstanding_invoices_past_their_due_date(): void
    {
        $student = Student::create(['name' => 'Test Student']);
        $cashAccountId = $this->makeCashAccount()->id;

        $overdue = Invoice::create([
            'student_id' => $student->id, 'cash_account_id' => $cashAccountId, 'total_amount' => 100, 'paid_amount' => 0, 'remaining_amount' => 100,
            'status' => Invoice::STATUS_UNPAID, 'due_date' => now()->subDays(10)->toDateString(),
        ]);
        $notYetDue = Invoice::create([
            'student_id' => $student->id, 'cash_account_id' => $cashAccountId, 'total_amount' => 100, 'paid_amount' => 0, 'remaining_amount' => 100,
            'status' => Invoice::STATUS_UNPAID, 'due_date' => now()->addDays(10)->toDateString(),
        ]);
        $paidButPastDue = Invoice::create([
            'student_id' => $student->id, 'cash_account_id' => $cashAccountId, 'total_amount' => 100, 'paid_amount' => 100, 'remaining_amount' => 0,
            'status' => Invoice::STATUS_PAID, 'due_date' => now()->subDays(10)->toDateString(),
        ]);

        $overdueIds = Invoice::overdue()->pluck('id');

        $this->assertTrue($overdueIds->contains($overdue->id));
        $this->assertFalse($overdueIds->contains($notYetDue->id));
        $this->assertFalse($overdueIds->contains($paidButPastDue->id));
    }

    public function test_invoice_item_traces_back_to_the_subscription_that_generated_it(): void
    {
        $enrollment = $this->makeEnrollment();
        $fee = Fee::create(['name_ru' => 'Регистрация', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => 8000, 'is_active' => true]);
        $subscription = (new \App\Services\StudentServiceSubscriptionService())->subscribe($enrollment, $fee);

        $invoice = Invoice::create([
            'student_id' => $enrollment->student_id,
            'cash_account_id' => $this->makeCashAccount()->id,
            'total_amount' => 8000, 'paid_amount' => 0, 'remaining_amount' => 8000,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'fee_id' => $fee->id,
            'subscription_id' => $subscription->id,
            'description' => $fee->name_ru,
            'amount' => 8000,
        ]);

        $this->assertTrue($item->fresh()->subscription->is($subscription));
    }

    /*
    |--------------------------------------------------------------------------
    | Price versioning: never retroactively alter an already-issued invoice
    |--------------------------------------------------------------------------
    */

    public function test_a_mid_year_price_change_does_not_alter_an_already_issued_invoice_line(): void
    {
        $enrollment = $this->makeEnrollment();
        $fee = Fee::create(['name_ru' => 'Транспорт', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => 1000, 'is_active' => true]);

        FeePrice::create([
            'fee_id' => $fee->id,
            'amount' => 1000,
            'start_date' => '2026-09-01',
            'is_active' => true,
        ]);

        $invoice = Invoice::create([
            'student_id' => $enrollment->student_id,
            'cash_account_id' => $this->makeCashAccount()->id,
            'total_amount' => 1000, 'paid_amount' => 0, 'remaining_amount' => 1000,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'fee_id' => $fee->id,
            'description' => $fee->name_ru,
            'amount' => $fee->currentPrice('2026-09-15'),
        ]);

        $this->assertSame(1000.0, (float) $item->amount);

        // A new price takes effect from November — should never retroactively
        // change the September invoice line already issued above.
        FeePrice::create([
            'fee_id' => $fee->id,
            'amount' => 1500,
            'start_date' => '2026-11-01',
            'is_active' => true,
        ]);

        $this->assertSame(1000.0, (float) $item->fresh()->amount);
        $this->assertSame(1500.0, $fee->currentPrice('2026-11-15'));
        $this->assertSame(1000.0, $fee->currentPrice('2026-09-15'));
    }
}
