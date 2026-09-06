<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentPlan;
use Illuminate\Support\Facades\DB;

/**
 * Finance V2 Phase 1 UI — Quick Registration Section 4 (Порядок оплаты) and
 * Section 2's per-service billing controls.
 *
 * Phase 1's backend (payment_type='mixed', services.*.billing_strategy/
 * payment_period) already shipped and is fully covered by
 * {@see QuickRegistrationMixedBillingTest} — this suite is UI/request-wiring
 * only: it never changes StoreQuickStudentRegistrationRequest, the service
 * layer, or InvoiceIssuanceService, and never re-tests the settlement math
 * those already cover. It verifies that:
 *  - Section 4 no longer offers a global payment_type/billing_period pair —
 *    only "auto" (per-service, computed client-side) and "plan" (unchanged
 *    custom installment path) remain.
 *  - Additional services (books/extra_classes/activity/other) — previously
 *    always once-only with no billing control at all — now get a compact
 *    per-service period control when the Fee is actually calendar-capable,
 *    built from exactly the same canonical source (Fee::allowedBillingPeriods())
 *    Tuition's pre-existing dropdown already used, and nothing at all when
 *    it is not (byte-identical to before this pass in that case).
 *  - Custom PaymentPlan is never exposed per service.
 *  - The exact payload shape the new page script would submit (payment_type
 *    computed as 'mixed'/'one_time' rather than typed by the operator) is
 *    accepted end-to-end by the completely unchanged backend.
 */
class QuickRegistrationMixedBillingUiTest extends QuickRegistrationUxTestCase
{
    private function additionalFee(string $name, array $calendarPeriods = [], string $category = Fee::CATEGORY_EXTRA_CLASSES): Fee
    {
        $fee = Fee::create(['name_ru' => $name, 'category' => $category, 'amount' => '300.00', 'is_active' => true]);
        foreach ($calendarPeriods as $period) {
            $fee->billingPeriods()->create(['billing_period' => $period]);
        }

        return $fee;
    }

    // ------------------------------------------------------------------
    // Section 4 no longer asks for a global payment_type/billing_period.
    // ------------------------------------------------------------------
    public function test_section_four_no_longer_offers_a_global_calendar_option(): void
    {
        [$year] = $this->structure();
        $this->fee();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));

        $response->assertOk()
            ->assertDontSee('Периодическая оплата (по календарю)')
            ->assertDontSee('name="billing_period"', false)
            ->assertDontSee('id="billing-period"', false)
            ->assertSee('Автоматически по каждой услуге')
            ->assertSee('Рассрочка (индивидуальный план)')
            ->assertSee('id="payment-mode"', false)
            ->assertSee('name="payment_type" id="payment-type-input" value="mixed"', false);
    }

    // ------------------------------------------------------------------
    // Additional services: >1 allowed calendar period → compact dropdown,
    // restricted to exactly those periods.
    // ------------------------------------------------------------------
    public function test_additional_service_with_multiple_periods_renders_dropdown_with_only_allowed_periods(): void
    {
        [$year] = $this->structure();
        $fee = $this->additionalFee('Кружок робототехники', ['monthly', 'quarterly']);
        // Only a monthly FeePrice is configured — quarterly must still be
        // offered via the same monthly×3 derivation InvoiceCalculationService
        // itself performs, never requiring an explicit quarterly row.
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '300.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'is_active' => true,
            'payment_period' => 'monthly',
        ]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));

        $response->assertOk()
            ->assertSee('requires-period', false)
            ->assertSee('Порядок оплаты *')
            ->assertSee('Ежемесячно')
            ->assertSee('Ежеквартально');
        // Never offers the one period this Fee was NOT configured for.
        $response->assertDontSee('>Ежегодно<', false);
    }

    // ------------------------------------------------------------------
    // Additional services: a period must be both canonically allowed AND
    // actually purchasable (a matching FeePrice, or the same monthly→
    // quarterly derivation InvoiceCalculationService itself performs) —
    // billingPeriods alone is never enough.
    // ------------------------------------------------------------------
    public function test_additional_service_period_with_no_matching_feeprice_is_not_offered(): void
    {
        [$year] = $this->structure();
        // Configured for monthly billing, but no FeePrice at all — nothing
        // to derive from and no direct match either.
        $this->additionalFee('Клуб без тарифа', ['monthly']);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));

        $response->assertOk()
            ->assertDontSee('form-select price-option requires-period', false)
            ->assertDontSee('Ежемесячно (автоматически)')
            // Never silently offered as a one-time service either — a
            // periodic tariff configured but unpriced must fail closed,
            // not quietly become billing_strategy=once.
            ->assertDontSee('Разовая оплата')
            ->assertSee('действующая цена не определена');
    }

    // ------------------------------------------------------------------
    // Additional services: exactly 1 allowed period → auto-selected,
    // no dropdown, no operator interaction.
    // ------------------------------------------------------------------
    public function test_additional_service_with_exactly_one_period_auto_selects_without_a_dropdown(): void
    {
        [$year] = $this->structure();
        $fee = $this->additionalFee('Секция плавания', ['quarterly']);
        // Direct match this time (distinct from the derivation path already
        // covered above) — an explicit quarterly FeePrice, proving the
        // purchasability check accepts a direct price too, not only the
        // monthly-derived case.
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '600.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'is_active' => true,
            'payment_period' => 'quarterly',
        ]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));

        // "requires-period" as a bare substring also appears inside the
        // page's shared inline <script> (the JS selector that reads it) on
        // every render regardless of markup — assert against the actual
        // class-bearing <select> attribute string instead.
        $response->assertOk()
            ->assertDontSee('form-select price-option requires-period', false)
            ->assertSee('value="quarterly"', false)
            ->assertSee('Ежеквартально (автоматически)');
    }

    // ------------------------------------------------------------------
    // Additional services: no allowed calendar period → quiet "once" label,
    // no payment_period field at all (byte-identical to pre-Phase-1 UI).
    // ------------------------------------------------------------------
    public function test_once_only_additional_service_shows_quiet_label_and_no_period_field(): void
    {
        [$year] = $this->structure();
        $fee = $this->additionalFee('Учебники', []);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));

        $response->assertOk()
            ->assertSee('Разовая оплата')
            ->assertDontSee('form-select price-option requires-period', false);
    }

    // ------------------------------------------------------------------
    // Custom PaymentPlan is never offered per service — only the single
    // global selector inside the "plan" mode of Section 4.
    // ------------------------------------------------------------------
    public function test_custom_payment_plan_is_not_exposed_as_a_per_service_control(): void
    {
        [$year] = $this->structure();
        $fee = $this->additionalFee('Кружок робототехники', ['monthly', 'quarterly']);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));

        $response->assertOk()->assertSee('id="payment-plan-id"', false);
        $this->assertMatchesRegularExpression('/id="payment-plan-id"/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/services\[\d+\]\[payment_plan_id\]/', $response->getContent());
    }

    // ------------------------------------------------------------------
    // Payment section (5) and the live summary table are structurally
    // unchanged by this pass.
    // ------------------------------------------------------------------
    public function test_payment_section_and_summary_structure_are_unchanged(): void
    {
        [$year] = $this->structure();
        $this->fee();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));

        $response->assertOk()
            ->assertSee('name="payment_method"', false)
            ->assertSee('name="cash_account_id"', false)
            ->assertSee('name="payment_note"', false)
            ->assertSee('id="live-summary"', false)
            ->assertSee('5. Оплата');
    }

    // ------------------------------------------------------------------
    // End-to-end: the exact payload the new page script builds for a
    // Registration(once) + Tuition(monthly) + Additional(quarterly, auto-
    // selected) submission is accepted by the completely unchanged backend.
    // ------------------------------------------------------------------
    public function test_mixed_payload_covers_registration_tuition_and_additional_calendar_service(): void
    {
        [$year, $stage, $grade, $class, $mode] = $this->structure();

        $registration = $this->fee('Организационный взнос', Fee::CATEGORY_REGISTRATION);

        $tuition = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => true]);
        $tuition->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create([
            'fee_id' => $tuition->id, 'academic_year_id' => $year->id, 'amount' => '2000.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'is_active' => true,
            'payment_period' => 'monthly', 'grade_group' => '1–4 классы',
        ]);

        // Additional service, quarterly-only — the new auto-selected path.
        $club = $this->additionalFee('Секция плавания', ['quarterly']);
        FeePrice::create([
            'fee_id' => $club->id, 'academic_year_id' => $year->id, 'amount' => '600.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'is_active' => true,
            'payment_period' => 'quarterly',
        ]);
        // createAutomaticCoverage() always derives its basis from a MONTHLY
        // tariff regardless of the invoice's own billing_period — an
        // existing, unchanged requirement (see QuickRegistrationMixedBillingTest::
        // transportFee()), never a new Phase 1 UI rule.
        FeePrice::create([
            'fee_id' => $club->id, 'academic_year_id' => $year->id, 'amount' => '200.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'is_active' => true,
            'payment_period' => 'monthly',
        ]);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload(
            [$year, $stage, $grade, $class, $mode], $registration, [
                'registration_date' => $year->start_date->toDateString(),
                'payment_type' => 'mixed',
                'services' => [
                    ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00'],
                    ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
                    ['fee_id' => $club->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'quarterly'],
                ],
            ]
        ));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('invoice_items', 3);
        $invoice = Invoice::sole();
        $this->assertSame(Fee::CATEGORY_EXTRA_CLASSES, $club->category);
        $this->assertNotNull(InvoiceItem::where('fee_id', $club->id)->first());
        $this->assertGreaterThan(0, (float) $invoice->total_amount);
    }

    // ------------------------------------------------------------------
    // Food-only under 'mixed': the new page script computes payment_type=
    // 'mixed' whenever Food is selected (never the legacy 'calendar' value)
    // — even with no other service present. Food's own duration-mode path
    // is completely untouched.
    // ------------------------------------------------------------------
    public function test_food_only_submission_uses_mixed_payment_type_and_preserves_duration_fields(): void
    {
        [$year, $stage, $grade, $class, $mode] = $this->structure();
        \App\Models\AcademicCalendar::create(['academic_year_id' => $year->id, 'weekly_days_off' => ['fri', 'sat']]);
        $food = $this->fee('Питание', Fee::CATEGORY_FOOD);
        $plan = \App\Models\MealPlan::create(['name_ru' => 'Комплексное питание', 'meal_type' => \App\Models\MealPlan::TYPE_BOTH, 'period' => \App\Models\MealPlan::PERIOD_DAILY, 'price' => '85.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $food->id, 'academic_year_id' => $year->id, 'amount' => '85.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'is_active' => true,
            'option_type' => 'meal_plan', 'option_value' => (string) $plan->id, 'payment_period' => 'daily',
        ]);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload(
            [$year, $stage, $grade, $class, $mode], $food, [
                'registration_date' => $year->start_date->toDateString(),
                'payment_type' => 'mixed',
                'services' => [
                    // 2026-08-01 (this fixture's year start) falls on the
                    // configured weekly day off — 2026-08-03 is a genuine
                    // teaching day, matching QuickRegistrationMixedBillingTest's
                    // own foodFee() fixture convention.
                    ['fee_id' => $food->id, 'quantity' => 1, 'paid_now' => '0.00', 'meal_plan_id' => $plan->id, 'food_duration_mode' => 'day', 'food_date' => '2026-08-03'],
                ],
            ]
        ));

        $response->assertSessionHasNoErrors();
        $foodItem = InvoiceItem::where('fee_id', $food->id)->sole();
        $this->assertSame('85.00', $foodItem->amount, 'Food priced correctly through mixed with no other service present');
    }

    // ------------------------------------------------------------------
    // Once-only everything (no calendar anywhere): the new page script
    // falls back to the legacy payment_type='one_time' path unchanged —
    // no billing_strategy is ever sent for a once-only Additional service.
    // ------------------------------------------------------------------
    public function test_once_only_additional_service_uses_legacy_one_time_payment_type(): void
    {
        [$year, $stage, $grade, $class, $mode] = $this->structure();
        $registration = $this->fee('Организационный взнос', Fee::CATEGORY_REGISTRATION);
        $books = $this->additionalFee('Учебники', [], Fee::CATEGORY_BOOKS);
        FeePrice::create([
            'fee_id' => $books->id, 'academic_year_id' => $year->id, 'amount' => '400.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'is_active' => true,
        ]);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload(
            [$year, $stage, $grade, $class, $mode], $registration, [
                'registration_date' => $year->start_date->toDateString(),
                'payment_type' => 'one_time',
                'services' => [
                    ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00'],
                    ['fee_id' => $books->id, 'quantity' => 1, 'paid_now' => '0.00'],
                ],
            ]
        ));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('invoice_items', 2);
    }

    // ------------------------------------------------------------------
    // Defense-in-depth: a period outside this Additional service's own
    // configured set is rejected even when crafted directly, bypassing
    // the UI's own dropdown restriction entirely.
    // ------------------------------------------------------------------
    public function test_unsupported_additional_service_period_is_rejected(): void
    {
        [$year, $stage, $grade, $class, $mode] = $this->structure();
        $club = $this->additionalFee('Секция плавания', ['monthly']);
        FeePrice::create([
            'fee_id' => $club->id, 'academic_year_id' => $year->id, 'amount' => '200.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'is_active' => true,
            'payment_period' => 'monthly',
        ]);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload(
            [$year, $stage, $grade, $class, $mode], $club, [
                'registration_date' => $year->start_date->toDateString(),
                'payment_type' => 'mixed',
                'services' => [
                    ['fee_id' => $club->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'quarterly'],
                ],
            ]
        ));

        $response->assertSessionHasErrors('services.0.payment_period');
        $this->assertDatabaseCount('invoices', 0);
    }

    // ------------------------------------------------------------------
    // A per-service custom PaymentPlan attempt (crafted directly, bypassing
    // the UI) still fails closed exactly as the backend already guarantees
    // — this pass introduces no new escape hatch around that rule.
    // ------------------------------------------------------------------
    public function test_per_service_custom_plan_still_fails_closed_under_mixed(): void
    {
        [$year, $stage, $grade, $class, $mode] = $this->structure();
        $registration = $this->fee('Организационный взнос', Fee::CATEGORY_REGISTRATION);
        $plan = PaymentPlan::create(['name_ru' => 'План', 'is_active' => true]);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload(
            [$year, $stage, $grade, $class, $mode], $registration, [
                'registration_date' => $year->start_date->toDateString(),
                'payment_type' => 'mixed',
                'payment_plan_id' => $plan->id,
                'services' => [
                    ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00'],
                ],
            ]
        ));

        $response->assertSessionHasErrors('payment_plan_id');
        $this->assertDatabaseCount('invoices', 0);
    }

    // ------------------------------------------------------------------
    // Review corrective pass (FIX 3) — the exact payload the new page
    // script sends when the operator picks "план" mode: payment_type=
    // 'plan', a valid payment_plan_id, and billing_strategy forced to
    // 'once' on every row (never 'calendar', regardless of what that
    // row's own payment_period happens to hold). Registration succeeds
    // through the completely unchanged legacy plan path, producing the
    // same installment shape a direct (pre-existing) plan submission
    // already does — see QuickRegistrationBillingSchedulesTest::
    // test_a_fee_with_an_assigned_plan_accepts_only_that_plan().
    // ------------------------------------------------------------------
    public function test_plan_mode_end_to_end_matches_existing_plan_semantics(): void
    {
        [$year, $stage, $grade, $class, $mode] = $this->structure();
        $tuition = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1000.00', 'is_active' => true]);
        $tuition->billingPeriods()->create(['billing_period' => 'custom_plan']);
        $plan = PaymentPlan::create(['name_ru' => 'Назначенный план', 'is_active' => true]);
        $plan->installments()->create(['name_ru' => 'Единственный этап', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100']);
        $tuition->assignedPaymentPlans()->attach($plan->id);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload(
            [$year, $stage, $grade, $class, $mode], $tuition, [
                'registration_date' => $year->start_date->toDateString(),
                'payment_type' => 'plan',
                'payment_plan_id' => $plan->id,
                'services' => [
                    // billing_strategy='once' is exactly what the page
                    // script now forces onto every row once "план" mode
                    // is selected — sending it must remain inert, never
                    // trigger mixed-style processing.
                    ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'once'],
                ],
            ]
        ));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $invoice = Invoice::sole();
        // payment_type is a request-only field (never persisted on Invoice)
        // — the plan path is verified by its actual installment shape
        // instead: matches the pre-existing direct-plan test's own shape
        // exactly (one 100% installment, from the assigned plan), never a
        // mixed-strategy schedule.
        $this->assertSame(1, $invoice->installments()->count());
        $this->assertDatabaseCount('invoice_items', 1);
    }

    // ------------------------------------------------------------------
    // Review corrective pass (FIX 3) — plan → auto regression: once the
    // operator abandons "план" and switches back to "автоматически", the
    // page script must never submit the previously-chosen payment_plan_id
    // alongside the resulting mixed payload. This documents both halves
    // of that guarantee: the corrected client's actual payload (no
    // payment_plan_id at all) succeeds, and the backend's own independent
    // safety net (StoreQuickStudentRegistrationRequest::after()) still
    // fails closed if a stale value ever reached it anyway.
    // ------------------------------------------------------------------
    public function test_switching_from_plan_to_auto_must_not_leak_a_previously_selected_payment_plan_id(): void
    {
        [$year, $stage, $grade, $class, $mode] = $this->structure();
        $tuition = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => true]);
        $tuition->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create([
            'fee_id' => $tuition->id, 'academic_year_id' => $year->id, 'amount' => '2000.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'is_active' => true,
            'payment_period' => 'monthly', 'grade_group' => '1–4 классы',
        ]);
        $plan = PaymentPlan::create(['name_ru' => 'План (был выбран, затем отменён)', 'is_active' => true]);

        $mixedServicePayload = [
            ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
        ];

        // A) The corrected client's actual behavior once mode is switched
        // back to "auto": payment_plan_id is disabled, so it is never part
        // of the submitted payload at all.
        $clean = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload(
            [$year, $stage, $grade, $class, $mode], $tuition, [
                'registration_date' => $year->start_date->toDateString(),
                'payment_type' => 'mixed',
                'services' => $mixedServicePayload,
            ]
        ));
        $clean->assertSessionHasNoErrors();
        // Resolves through the mixed monthly-schedule path, not the
        // single-installment plan shape — confirms this really went
        // through 'mixed' processing, not a stray 'plan' interpretation.
        $this->assertGreaterThan(1, Invoice::sole()->installments()->count());

        // B) Defense-in-depth: even if a stale payment_plan_id somehow
        // still reached the server (the exact bug FIX 1 removes at the
        // client), the request layer independently rejects it — this
        // pass adds no new trust in a client-disabled field alone.
        $leaked = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload(
            [$year, $stage, $grade, $class, $mode], $tuition, [
                'registration_date' => $year->start_date->toDateString(),
                'payment_type' => 'mixed',
                'payment_plan_id' => $plan->id,
                'services' => $mixedServicePayload,
            ]
        ));
        $leaked->assertSessionHasErrors('payment_plan_id');
        $this->assertSame(1, Invoice::count(), 'the leaked-payment_plan_id attempt must create no second invoice');
    }

    // ------------------------------------------------------------------
    // A stray billing_strategy=calendar under payment_type=plan is
    // rejected outright, not silently reinterpreted as mixed processing
    // — the plan path never trusts per-service mixed fields.
    // ------------------------------------------------------------------
    public function test_stray_calendar_billing_strategy_under_plan_is_rejected(): void
    {
        [$year, $stage, $grade, $class, $mode] = $this->structure();
        $tuition = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1000.00', 'is_active' => true]);
        $tuition->billingPeriods()->create(['billing_period' => 'custom_plan']);
        $plan = PaymentPlan::create(['name_ru' => 'Назначенный план', 'is_active' => true]);
        $plan->installments()->create(['name_ru' => 'Единственный этап', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100']);
        $tuition->assignedPaymentPlans()->attach($plan->id);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload(
            [$year, $stage, $grade, $class, $mode], $tuition, [
                'registration_date' => $year->start_date->toDateString(),
                'payment_type' => 'plan',
                'payment_plan_id' => $plan->id,
                'services' => [
                    ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar'],
                ],
            ]
        ));

        $response->assertSessionHasErrors('services.0.billing_strategy');
        $this->assertDatabaseCount('invoices', 0);
    }
}
