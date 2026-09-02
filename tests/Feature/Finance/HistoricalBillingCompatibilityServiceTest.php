<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeeBillingPeriod;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\InvoiceItem;
use App\Models\PaymentPlan;
use App\Services\Finance\HistoricalBillingCompatibilityService;
use App\Services\Finance\InvoiceIssuanceService;

/**
 * Finance V2, Phase 2B corrective pass (review finding H1).
 *
 * Proves HistoricalBillingCompatibilityService correctly identifies a Fee
 * that was ACTUALLY invoiced with a custom PaymentPlan before Fee-scoped
 * billing validation existed, grants it exactly the plan(s) it was really
 * used with (never a blanket grant to every Fee), is idempotent, and —
 * critically — that the grant actually unblocks the real InvoiceIssuanceService
 * flow, not just the two config tables in isolation.
 */
class HistoricalBillingCompatibilityServiceTest extends FinanceOperationsTestCase
{
    private function historicalPlanInvoice(Fee $fee, PaymentPlan $plan, string $amount = '500.00'): Invoice
    {
        $invoice = Invoice::create([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'customer_name' => $this->student->full_name, 'currency' => 'EGP',
            'subtotal_amount' => $amount, 'total_amount' => $amount, 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => $amount, 'status' => 'unpaid',
            'due_date' => '2027-01-01', 'created_by' => $this->accountant->id,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, '2026');
        $invoice->save();

        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'fee_id' => $fee->id, 'description' => $fee->name_ru,
            'unit_price' => $amount, 'quantity' => 1, 'amount' => $amount,
            'paid_amount' => '0.00', 'remaining_amount' => $amount,
        ]);

        // Simulates the pre-Phase-2B world: a plan-based installment
        // schedule was generated for this Fee even though (per this test's
        // whole premise) nothing ever explicitly assigned that plan to
        // this Fee — exactly what InstallmentPlanService::generate() would
        // have produced before Phase 2B's Fee-scoping existed.
        InvoiceInstallment::create([
            'invoice_id' => $invoice->id, 'payment_plan_id' => $plan->id,
            'name_ru' => 'Этап 1', 'sequence' => 1, 'due_date' => '2026-09-01',
            'amount' => $amount, 'paid_amount' => '0.00', 'remaining_amount' => $amount,
            'status' => 'pending',
        ]);

        return $invoice;
    }

    public function test_a_historically_used_non_covered_fee_regains_custom_plan_capability(): void
    {
        // A Fee category the Phase 2B migration's own default seeding never
        // touches (only registration/tuition*/transport/food are covered).
        $uniform = Fee::create(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '500.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $uniform->id, 'academic_year_id' => $this->year->id, 'amount' => '500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $plan = PaymentPlan::create(['name_ru' => 'Исторический план 50/50', 'is_active' => true]);
        $plan->installments()->create(['name_ru' => 'Этап 1', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '50']);
        $plan->installments()->create(['name_ru' => 'Этап 2', 'sequence' => 2, 'offset_days' => 30, 'percentage' => '50']);

        $this->assertFalse($uniform->allowsBillingPeriod(FeeBillingPeriod::PERIOD_CUSTOM_PLAN), 'sanity: not allowed before the repair runs');
        $this->assertFalse($uniform->assignedPaymentPlans()->where('payment_plans.id', $plan->id)->exists());

        $this->historicalPlanInvoice($uniform, $plan);

        $granted = app(HistoricalBillingCompatibilityService::class)->grantHistoricalCustomPlanAssignments();

        $this->assertContains(['fee_id' => $uniform->id, 'payment_plan_id' => $plan->id], $granted);

        $uniform->refresh();
        $this->assertTrue($uniform->allowsBillingPeriod(FeeBillingPeriod::PERIOD_CUSTOM_PLAN));
        $this->assertTrue($uniform->assignedPaymentPlans()->where('payment_plans.id', $plan->id)->exists());

        // Idempotency: calling it again must not create duplicate rows —
        // both tables have unique constraints the service relies on
        // (firstOrCreate / insertOrIgnore), but assert the outcome directly
        // rather than only trusting the absence of a DB exception.
        app(HistoricalBillingCompatibilityService::class)->grantHistoricalCustomPlanAssignments();
        $this->assertSame(1, FeeBillingPeriod::where('fee_id', $uniform->id)->where('billing_period', FeeBillingPeriod::PERIOD_CUSTOM_PLAN)->count());
        $this->assertSame(1, $uniform->assignedPaymentPlans()->where('payment_plans.id', $plan->id)->count());

        // The real proof: a FRESH invoice for this same Fee can now
        // actually be issued with this plan through the real, validated
        // InvoiceIssuanceService flow — not just the two config tables in
        // isolation.
        $freshInvoice = app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $uniform->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'plan', 'payment_plan_id' => $plan->id,
        ], $this->accountant);

        $this->assertSame(2, $freshInvoice->installments()->count());
        $this->assertSame('250.00', $freshInvoice->installments()->orderBy('sequence')->first()->amount);
    }

    public function test_a_fee_never_historically_used_with_a_plan_gets_no_grant(): void
    {
        $books = Fee::create(['name_ru' => 'Учебники', 'category' => Fee::CATEGORY_BOOKS, 'amount' => '300.00', 'is_active' => true]);
        // A plan exists in the system, and other Fees may have used it, but
        // THIS Fee never appeared on any plan-based invoice.
        $plan = PaymentPlan::create(['name_ru' => 'Неиспользуемый план', 'is_active' => true]);
        $plan->installments()->create(['name_ru' => 'Единственный этап', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100']);

        app(HistoricalBillingCompatibilityService::class)->grantHistoricalCustomPlanAssignments();

        $books->refresh();
        $this->assertFalse($books->allowsBillingPeriod(FeeBillingPeriod::PERIOD_CUSTOM_PLAN), 'never used historically — must not be granted');
        $this->assertSame(0, $books->assignedPaymentPlans()->count());
    }

    public function test_default_seeded_categories_are_unaffected_by_the_historical_repair(): void
    {
        // Tuition already gets monthly/quarterly/yearly from the migration's
        // own category seeding (not from this service) — the historical
        // repair must not interfere with or duplicate that.
        app(HistoricalBillingCompatibilityService::class)->grantHistoricalCustomPlanAssignments();

        $this->fee->refresh();
        $this->assertFalse($this->fee->allowsBillingPeriod(FeeBillingPeriod::PERIOD_CUSTOM_PLAN), 'tuition fixture never used a plan historically in this test — no spurious grant');
    }

    public function test_registration_fee_never_regains_custom_plan_capability_even_with_real_historical_usage(): void
    {
        // Pre-deploy safety patch: Registration is a hard "once only"
        // invariant. Even genuine, real (non-test) historical plan usage
        // must never grant it custom_plan — that would let historical data
        // silently override a non-negotiable business rule.
        $registration = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1000.00', 'is_active' => true]);
        $plan = PaymentPlan::create(['name_ru' => 'Реальный исторический план', 'is_active' => true, 'is_test_data' => false]);
        $plan->installments()->create(['name_ru' => 'Этап 1', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100']);

        $this->historicalPlanInvoice($registration, $plan);

        $granted = app(HistoricalBillingCompatibilityService::class)->grantHistoricalCustomPlanAssignments();

        $this->assertEmpty(array_filter($granted, fn ($row) => $row['fee_id'] === $registration->id), 'Registration must never be granted, regardless of historical usage');
        $registration->refresh();
        $this->assertFalse($registration->allowsBillingPeriod(FeeBillingPeriod::PERIOD_CUSTOM_PLAN));
        $this->assertSame(0, $registration->assignedPaymentPlans()->count());
    }

    public function test_test_flagged_payment_plan_historical_usage_is_ignored(): void
    {
        // A Fee whose only historical "usage" was against a plan flagged
        // is_test_data=true (e.g. UatMasterDataRepair's UAT 50/50 plan)
        // must gain nothing — pure fixture data is not real business
        // evidence.
        $uniform = Fee::create(['name_ru' => 'Школьная форма (тест)', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '500.00', 'is_active' => true]);
        $testPlan = PaymentPlan::create(['name_ru' => 'UAT — 2 платежа 50/50', 'is_active' => true, 'is_test_data' => true]);
        $testPlan->installments()->create(['name_ru' => 'Этап 1', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '50']);
        $testPlan->installments()->create(['name_ru' => 'Этап 2', 'sequence' => 2, 'offset_days' => 30, 'percentage' => '50']);

        $this->historicalPlanInvoice($uniform, $testPlan);

        $granted = app(HistoricalBillingCompatibilityService::class)->grantHistoricalCustomPlanAssignments();

        $this->assertEmpty(array_filter($granted, fn ($row) => $row['fee_id'] === $uniform->id), 'test-flagged plan usage must not count as historical evidence');
        $uniform->refresh();
        $this->assertFalse($uniform->allowsBillingPeriod(FeeBillingPeriod::PERIOD_CUSTOM_PLAN));
        $this->assertSame(0, $uniform->assignedPaymentPlans()->count());
    }

    public function test_real_historical_plan_usage_is_preserved_alongside_test_plan_exclusion(): void
    {
        // Both guards active in one run: a Fee used with a REAL plan still
        // gets granted; a Fee used only with the TEST plan does not — the
        // exclusion is scoped to the test plan specifically, not to the
        // whole detection mechanism.
        $transport = Fee::create(['name_ru' => 'Трансфер (доп.)', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '900.00', 'is_active' => true]);
        $realPlan = PaymentPlan::create(['name_ru' => 'Настоящий план', 'is_active' => true, 'is_test_data' => false]);
        $realPlan->installments()->create(['name_ru' => 'Этап 1', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100']);
        $this->historicalPlanInvoice($transport, $realPlan);

        $uniform = Fee::create(['name_ru' => 'Форма (доп.)', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '400.00', 'is_active' => true]);
        $testPlan = PaymentPlan::create(['name_ru' => 'UAT — 2 платежа 50/50 (доп.)', 'is_active' => true, 'is_test_data' => true]);
        $testPlan->installments()->create(['name_ru' => 'Этап 1', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100']);
        $this->historicalPlanInvoice($uniform, $testPlan);

        app(HistoricalBillingCompatibilityService::class)->grantHistoricalCustomPlanAssignments();

        $transport->refresh();
        $uniform->refresh();
        $this->assertTrue($transport->allowsBillingPeriod(FeeBillingPeriod::PERIOD_CUSTOM_PLAN), 'real plan usage still grants');
        $this->assertFalse($uniform->allowsBillingPeriod(FeeBillingPeriod::PERIOD_CUSTOM_PLAN), 'test plan usage still does not grant');
    }
}
