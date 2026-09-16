<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeeBillingPeriod;
use App\Models\PaymentPlan;
use App\Services\Finance\InvoicePaymentService;
use Illuminate\Support\Str;

/**
 * Payment Plan operational cleanup: "UAT — 2 платежа 50/50" (and any other
 * PaymentPlan flagged is_test_data=true) must never appear in a screen an
 * accountant uses to browse or assign a real plan, and the "Назначенные
 * планы рассрочки" section on the Finance service form must only be
 * live/persistable when "Индивидуальный план" (custom_plan) is actually
 * selected — enforced both client-side (hidden section) and server-side
 * (sync rejects a crafted submission).
 */
class PaymentPlanOperationalFilteringTest extends FinanceOperationsTestCase
{
    private function testPlan(): PaymentPlan
    {
        $plan = PaymentPlan::create(['name_ru' => 'UAT — 2 платежа 50/50', 'is_active' => true, 'is_test_data' => true]);
        $plan->installments()->create(['name_ru' => 'Этап 1', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '50']);
        $plan->installments()->create(['name_ru' => 'Этап 2', 'sequence' => 2, 'offset_days' => 30, 'percentage' => '50']);

        return $plan;
    }

    private function realPlan(): PaymentPlan
    {
        $plan = PaymentPlan::create(['name_ru' => 'Рассрочка на 3 месяца', 'is_active' => true]);
        $plan->installments()->create(['name_ru' => 'Этап 1', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '34']);
        $plan->installments()->create(['name_ru' => 'Этап 2', 'sequence' => 2, 'offset_days' => 30, 'percentage' => '33']);
        $plan->installments()->create(['name_ru' => 'Этап 3', 'sequence' => 3, 'offset_days' => 60, 'percentage' => '33']);

        return $plan;
    }

    public function test_test_plan_does_not_appear_in_service_catalog_selector_but_real_plan_does(): void
    {
        $testPlan = $this->testPlan();
        $realPlan = $this->realPlan();

        foreach ([
            route('dashboard.finance.services.create'),
            route('dashboard.finance.services.edit', $this->fee),
        ] as $url) {
            $response = $this->actingAs($this->accountant)->get($url);
            $response->assertOk();
            $ids = $response->viewData('paymentPlans')->pluck('id');
            $this->assertFalse($ids->contains($testPlan->id), "test plan must not appear at {$url}");
            $this->assertTrue($ids->contains($realPlan->id), "real plan must still appear at {$url}");
        }
    }

    public function test_test_plan_does_not_appear_in_quick_registration(): void
    {
        $testPlan = $this->testPlan();
        $realPlan = $this->realPlan();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));
        $response->assertOk();
        $ids = $response->viewData('paymentPlans')->pluck('id');
        $this->assertFalse($ids->contains($testPlan->id));
        $this->assertTrue($ids->contains($realPlan->id));
    }

    public function test_test_plan_does_not_appear_in_existing_student_invoice_plan_selection(): void
    {
        $testPlan = $this->testPlan();
        $realPlan = $this->realPlan();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.invoices.create', $this->student));
        $response->assertOk();
        $ids = $response->viewData('paymentPlans')->pluck('id');
        $this->assertFalse($ids->contains($testPlan->id));
        $this->assertTrue($ids->contains($realPlan->id));
    }

    public function test_test_plan_does_not_appear_in_payment_plan_management_list(): void
    {
        $testPlan = $this->testPlan();
        $realPlan = $this->realPlan();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.payment-plans.index'));
        $response->assertOk();
        $names = $response->viewData('plans')->pluck('name_ru');
        $this->assertFalse($names->contains('UAT — 2 платежа 50/50'));
        $this->assertTrue($names->contains($realPlan->name_ru));
    }

    private function assignedPlanSectionHasDNone(string $html): bool
    {
        preg_match('/class="([^"]*)"\s+id="assigned-payment-plans-section"/', $html, $matches);
        $this->assertNotEmpty($matches, 'assigned-payment-plans-section element not found in the response HTML');

        return str_contains($matches[1], 'd-none');
    }

    public function test_assigned_plan_section_is_hidden_when_custom_plan_is_unchecked(): void
    {
        // A fresh Fee with no billing periods selected at all — custom_plan unchecked.
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.services.create'));
        $response->assertOk();
        $this->assertTrue($this->assignedPlanSectionHasDNone($response->getContent()));
    }

    public function test_assigned_plan_section_is_visible_when_custom_plan_is_checked(): void
    {
        $this->fee->billingPeriods()->create(['billing_period' => FeeBillingPeriod::PERIOD_CUSTOM_PLAN]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.services.edit', $this->fee));
        $response->assertOk();
        $this->assertFalse($this->assignedPlanSectionHasDNone($response->getContent()));
    }

    public function test_crafted_post_with_payment_plan_ids_but_without_custom_plan_does_not_persist_the_assignment(): void
    {
        $realPlan = $this->realPlan();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.finance.services.store'), [
            'name_ru' => 'Тестовая услуга',
            'category' => Fee::CATEGORY_OTHER,
            'type' => 'service',
            'is_active' => true,
            'is_non_refundable' => false,
            'billing_periods' => ['monthly'], // custom_plan deliberately NOT included
            'payment_plan_ids' => [$realPlan->id], // crafted: present anyway
        ]);

        $response->assertSessionDoesntHaveErrors();
        $created = Fee::where('name_ru', 'Тестовая услуга')->sole();
        $this->assertSame(0, $created->assignedPaymentPlans()->count());
    }

    public function test_with_custom_plan_selected_a_legitimate_plan_can_still_be_assigned(): void
    {
        $realPlan = $this->realPlan();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.finance.services.store'), [
            'name_ru' => 'Услуга с индивидуальным планом',
            'category' => Fee::CATEGORY_OTHER,
            'type' => 'service',
            'is_active' => true,
            'is_non_refundable' => false,
            'billing_periods' => ['custom_plan'],
            'payment_plan_ids' => [$realPlan->id],
        ]);

        $response->assertSessionDoesntHaveErrors();
        $created = Fee::where('name_ru', 'Услуга с индивидуальным планом')->sole();
        $this->assertSame([$realPlan->id], $created->assignedPaymentPlans()->pluck('payment_plans.id')->all());
    }

    public function test_removing_custom_plan_from_an_existing_fee_detaches_its_assigned_plans(): void
    {
        $realPlan = $this->realPlan();
        $this->fee->billingPeriods()->create(['billing_period' => FeeBillingPeriod::PERIOD_CUSTOM_PLAN]);
        $this->fee->assignedPaymentPlans()->attach($realPlan->id);
        $this->assertSame(1, $this->fee->assignedPaymentPlans()->count());

        $response = $this->actingAs($this->accountant)->put(route('dashboard.finance.services.update', $this->fee), [
            'name_ru' => $this->fee->name_ru,
            'category' => $this->fee->category,
            'type' => 'service',
            'is_active' => true,
            'is_non_refundable' => false,
            'billing_periods' => ['monthly'], // custom_plan removed
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(0, $this->fee->assignedPaymentPlans()->count());
    }

    public function test_standard_billing_periods_remain_unaffected(): void
    {
        $response = $this->actingAs($this->accountant)->post(route('dashboard.finance.services.store'), [
            'name_ru' => 'Услуга со стандартными периодами',
            'category' => Fee::CATEGORY_OTHER,
            'type' => 'service',
            'is_active' => true,
            'is_non_refundable' => false,
            'billing_periods' => ['once', 'monthly', 'quarterly', 'yearly'],
        ]);

        $response->assertSessionDoesntHaveErrors();
        $created = Fee::where('name_ru', 'Услуга со стандартными периодами')->sole();
        $this->assertEqualsCanonicalizing(
            ['once', 'monthly', 'quarterly', 'yearly'],
            $created->billingPeriods()->pluck('billing_period')->all(),
        );
        $this->assertSame(0, $created->assignedPaymentPlans()->count());
    }

    public function test_partial_payment_and_debt_behavior_remain_unaffected(): void
    {
        $invoice = $this->invoice('1200.00');

        $payment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->cash->id,
            amount: '500.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $invoice->refresh();
        $this->assertSame('500.00', $payment->amount);
        $this->assertSame('500.00', $invoice->paid_amount);
        $this->assertSame('700.00', $invoice->remaining_amount);
        $this->assertSame('partial', $invoice->status);
    }
}
