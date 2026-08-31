<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeeBillingPeriod;
use App\Models\Invoice;
use App\Models\PaymentPlan;

/**
 * Finance V2, Phase 2B corrective pass (review finding M2).
 *
 * Classic Invoice's PaymentPlan dropdown is filtered client-side by a
 * feePlanMap the create() view now receives (see
 * StudentInvoiceController::create()) — not testable at the JS level in
 * this Pest suite, so this proves the two things that ARE server-testable
 * and actually matter: (1) the map handed to the view correctly reflects
 * real Fee-scoped assignments, and (2) the server-side backstop in
 * InvoiceIssuanceService::issue() — the authoritative rule regardless of
 * what any dropdown shows — still rejects an unassigned plan and accepts
 * an assigned one when submitted through THIS controller, exactly as it
 * already does for Quick Registration.
 */
class StudentInvoicePaymentPlanScopingTest extends FinanceOperationsTestCase
{
    public function test_create_view_receives_a_fee_scoped_plan_map_not_a_global_list(): void
    {
        $assigned = PaymentPlan::create(['name_ru' => 'Назначенный план', 'is_active' => true]);
        $assigned->installments()->create(['name_ru' => 'Единственный этап', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100']);
        $unassigned = PaymentPlan::create(['name_ru' => 'Не назначенный план', 'is_active' => true]);
        $unassigned->installments()->create(['name_ru' => 'Единственный этап', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100']);

        $this->fee->billingPeriods()->create(['billing_period' => FeeBillingPeriod::PERIOD_CUSTOM_PLAN]);
        $this->fee->assignedPaymentPlans()->attach($assigned->id);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.invoices.create', $this->student));

        $response->assertOk();
        $feePlanMap = $response->viewData('feePlanMap');
        $this->assertSame([$assigned->id], $feePlanMap->get($this->fee->id));
        $this->assertNotContains($unassigned->id, $feePlanMap->get($this->fee->id));
    }

    public function test_store_still_rejects_a_plan_not_assigned_to_the_invoiced_fee(): void
    {
        $unassigned = PaymentPlan::create(['name_ru' => 'Не назначенный план', 'is_active' => true]);
        $unassigned->installments()->create(['name_ru' => 'Единственный этап', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100']);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'pricing_date' => '2026-09-01', 'due_date' => '2027-01-01',
            'payment_type' => 'plan', 'payment_plan_id' => $unassigned->id,
            'fees' => [$this->fee->id],
        ]);

        $response->assertSessionHasErrors('payment_plan_id');
        $this->assertSame(0, Invoice::count());
    }

    public function test_store_accepts_a_plan_explicitly_assigned_to_the_invoiced_fee(): void
    {
        $assigned = PaymentPlan::create(['name_ru' => 'Назначенный план', 'is_active' => true]);
        $assigned->installments()->create(['name_ru' => 'Этап 1', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '60']);
        $assigned->installments()->create(['name_ru' => 'Этап 2', 'sequence' => 2, 'offset_days' => 30, 'percentage' => '40']);

        $this->fee->billingPeriods()->create(['billing_period' => FeeBillingPeriod::PERIOD_CUSTOM_PLAN]);
        $this->fee->assignedPaymentPlans()->attach($assigned->id);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'pricing_date' => '2026-09-01', 'due_date' => '2027-01-01',
            'payment_type' => 'plan', 'payment_plan_id' => $assigned->id,
            'fees' => [$this->fee->id],
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, Invoice::sole()->installments()->count());
    }
}
