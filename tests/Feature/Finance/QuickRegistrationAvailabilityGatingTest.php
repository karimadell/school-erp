<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\MealPlan;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 minimum-safe gating (task item 6): a service whose category needs
 * a dimensioned tariff must not be silently offered as a checkbox when zero
 * sellable tariffs exist for it. The availability check reads the SAME
 * FeePrice::sellable() rows InvoiceCalculationService resolves from (wired
 * through QuickStudentRegistrationController::create()'s eager load), so it
 * cannot drift from what submitting the form would actually do.
 */
class QuickRegistrationAvailabilityGatingTest extends QuickRegistrationUxTestCase
{
    private function price(Fee $fee, AcademicYear $year, array $dimensions): FeePrice
    {
        return FeePrice::create(array_merge([
            'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '100.00', 'currency' => 'EGP',
            'start_date' => $year->start_date, 'end_date' => $year->end_date, 'is_active' => true,
        ], $dimensions));
    }

    private function isServiceCheckboxDisabled(string $html, Fee $fee): bool
    {
        preg_match('/id="fee-'.$fee->id.'"([^>]*)>/', $html, $matches);
        $this->assertNotEmpty($matches, "Checkbox for fee #{$fee->id} not found in response.");

        return str_contains($matches[1], 'disabled');
    }

    /**
     * Food flexible-duration corrective pass: FinanceConfigurationReadinessService::
     * assessFood() (already-established, pre-existing behavior — not
     * introduced by the duration-mode work) additionally requires an
     * AcademicCalendar for the year, since Food's own day-count calculator
     * needs one, and requires the resolved tariff's payment_period to be
     * genuinely 'daily'. Both tests below configure an AcademicCalendar so
     * they exercise the actual tariff-presence gating they're named for,
     * rather than being masked by the calendar-missing reason.
     */
    public function test_food_is_disabled_when_no_meal_plan_backed_tariff_exists(): void
    {
        [$year] = $this->structure();
        \App\Models\AcademicCalendar::create(['academic_year_id' => $year->id, 'weekly_days_off' => ['fri', 'sat']]);
        $food = $this->fee('Питание', Fee::CATEGORY_FOOD);
        MealPlan::create(['name_ru' => 'Завтрак', 'meal_type' => 'breakfast', 'period' => 'daily', 'price' => '70.00', 'is_active' => true]);
        // A meal plan exists, but no FeePrice was ever configured for it.

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->getContent();

        $this->assertTrue($this->isServiceCheckboxDisabled($html, $food));
        $this->assertStringContainsString('Нет активного плана питания с дневным тарифом.', $html);
    }

    public function test_food_is_enabled_when_a_meal_plan_backed_tariff_exists(): void
    {
        [$year] = $this->structure();
        \App\Models\AcademicCalendar::create(['academic_year_id' => $year->id, 'weekly_days_off' => ['fri', 'sat']]);
        $food = $this->fee('Питание', Fee::CATEGORY_FOOD);
        $plan = MealPlan::create(['name_ru' => 'Завтрак', 'meal_type' => 'breakfast', 'period' => 'daily', 'price' => '70.00', 'is_active' => true]);
        $this->price($food, $year, ['option_type' => 'meal_plan', 'option_value' => (string) $plan->id, 'payment_period' => 'daily']);

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->getContent();

        $this->assertFalse($this->isServiceCheckboxDisabled($html, $food));
    }

    public function test_uniform_is_disabled_when_no_item_and_size_tariff_exists(): void
    {
        [$year] = $this->structure();
        $uniform = $this->fee('Школьная форма', Fee::CATEGORY_UNIFORM);
        DB::table('uniform_products')->insert(['name_ru' => 'Комплект', 'category' => 'set', 'size' => '6-10', 'price' => '2000.00', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        // A product exists in the catalog, but no matching FeePrice was configured.

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->getContent();

        $this->assertTrue($this->isServiceCheckboxDisabled($html, $uniform));
        $this->assertStringContainsString('Нет доступных тарифов на школьную форму.', $html);
    }

    public function test_uniform_is_enabled_when_a_sellable_item_and_size_tariff_exists(): void
    {
        [$year] = $this->structure();
        $uniform = $this->fee('Школьная форма', Fee::CATEGORY_UNIFORM);
        $this->price($uniform, $year, ['item' => 'Комплект', 'size' => '6-10', 'payment_period' => 'once']);
        DB::table('uniform_products')->insert(['name_ru' => 'Комплект', 'category' => 'set', 'size' => '6-10', 'price' => '2000.00', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->getContent();

        $this->assertFalse($this->isServiceCheckboxDisabled($html, $uniform));
    }

    /**
     * Phase 3, item 4: the uniform product dropdown itself — not just the
     * parent checkbox — must only offer item/size combinations that have a
     * real, sellable tariff. uniform_products has no FK to fee_prices, so
     * this is the one place that gap is closed.
     */
    public function test_only_uniform_products_with_a_sellable_tariff_appear_in_the_dropdown(): void
    {
        [$year] = $this->structure();
        $uniform = $this->fee('Школьная форма', Fee::CATEGORY_UNIFORM);
        $this->price($uniform, $year, ['item' => 'Комплект', 'size' => '6-10']);
        DB::table('uniform_products')->insert([
            ['name_ru' => 'Комплект', 'category' => 'set', 'size' => '6-10', 'price' => '2000.00', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            // Same catalog, but no matching tariff for this size — must not be offered.
            ['name_ru' => 'Комплект', 'category' => 'set', 'size' => '12-16', 'price' => '2500.00', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Комплект — 6-10', $html);
        $this->assertStringNotContainsString('Комплект — 12-16', $html);
    }

    public function test_transport_is_disabled_when_no_zone_tariff_exists(): void
    {
        [$year] = $this->structure();
        $transport = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->getContent();

        $this->assertTrue($this->isServiceCheckboxDisabled($html, $transport));
        $this->assertStringContainsString('Нет тарифа ни для одной транспортной зоны', $html);
    }

    public function test_transport_is_enabled_when_a_zone_tariff_exists(): void
    {
        [$year] = $this->structure();
        $transport = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        $this->price($transport, $year, ['option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly']);
        DB::table('transport_routes')->insert(['name' => 'Маршрут 1', 'created_at' => now(), 'updated_at' => now()]);

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->getContent();

        $this->assertFalse($this->isServiceCheckboxDisabled($html, $transport));
    }

    public function test_transport_is_disabled_when_pricing_exists_but_no_route_does(): void
    {
        // Phase 4A.3: transport_route_id is required on submit whenever
        // Transport is selected — pricing alone is not enough to enable it.
        [$year] = $this->structure();
        $transport = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        $this->price($transport, $year, ['option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly']);

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->getContent();

        $this->assertTrue($this->isServiceCheckboxDisabled($html, $transport));
        $this->assertStringContainsString('PRICING READY / ROUTE METADATA MISSING', $html);
    }

    public function test_installment_mode_is_disabled_when_no_active_payment_plan_exists(): void
    {
        $this->structure();
        $this->fee();

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="plan"[^>]*disabled[^>]*>/', $html);
        $this->assertStringContainsString('Нет активных планов рассрочки.', $html);
    }

    public function test_installment_mode_is_enabled_when_an_active_payment_plan_exists(): void
    {
        $this->structure();
        $this->fee();
        $plan = PaymentPlan::create(['name_ru' => 'Рассрочка на 3 месяца', 'is_active' => true, 'sort_order' => 1]);
        PaymentPlanInstallment::create(['payment_plan_id' => $plan->id, 'name_ru' => 'Этап 1', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '100.0000']);

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<option value="plan"[^>]*disabled[^>]*>/', $html);
    }

    public function test_a_stale_prior_year_price_does_not_count_as_available(): void
    {
        [$year] = $this->structure();
        $oldYear = AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => false]);
        $transport = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        $this->price($transport, $oldYear, ['option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly']);

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->getContent();

        $this->assertTrue($this->isServiceCheckboxDisabled($html, $transport));
    }

    public function test_an_inactive_price_does_not_count_as_available(): void
    {
        [$year] = $this->structure();
        $transport = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        $this->price($transport, $year, ['option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly', 'is_active' => false]);

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))
            ->assertOk()->getContent();

        $this->assertTrue($this->isServiceCheckboxDisabled($html, $transport));
    }

    public function test_availability_gating_never_mutates_financial_data(): void
    {
        [$year] = $this->structure();
        $this->fee('Питание', Fee::CATEGORY_FOOD);
        $this->fee('Школьная форма', Fee::CATEGORY_UNIFORM);
        $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);

        $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk();

        $this->assertSame(0, \App\Models\Invoice::count());
        $this->assertSame(0, \App\Models\Student::count());
        $this->assertSame(3, Fee::count());
        $this->assertSame(0, FeePrice::count());
    }

    public function test_specific_pricing_error_message_is_returned_instead_of_a_generic_one(): void
    {
        [$year, , $grade, , $mode] = $this->structure();
        $transport = $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT);
        $this->price($transport, $year, ['option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly']);

        $response = $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $transport->id,
            'quantity' => 1,
            'academic_year_id' => $year->id,
            'enrollment_mode_id' => $mode->id,
            'grade_id' => $grade->id,
            // Deliberately no transport_area/option_value — the zone tariff exists
            // but this selection can't resolve it.
        ]);

        $response->assertStatus(422);
        $message = $response->json('errors.fees.0');
        $this->assertNotNull($message);
        $this->assertStringContainsString('выберите все параметры тарифа', $message);
    }

    /**
     * Phase 3, task item 10: "employee cannot submit an unpriced enabled
     * service." Disabling the checkbox is a UX convenience — the real
     * guarantee is server-side. A crafted submission that bypasses the
     * disabled checkbox (e.g. a tampered request) must still be rejected,
     * and nothing may be created.
     */
    public function test_the_server_rejects_a_submission_for_a_service_with_no_configured_tariff_even_if_the_ui_would_have_blocked_it(): void
    {
        $structure = $this->structure();
        $food = $this->fee('Питание', Fee::CATEGORY_FOOD);
        // Zero FeePrice rows for $food — exactly the state that disables its
        // checkbox on the create screen (QuickRegistrationAvailabilityGatingTest
        // above). This submits it anyway, as a tampered request would.
        $plan = MealPlan::create(['name_ru' => 'Завтрак', 'meal_type' => 'breakfast', 'period' => 'daily', 'price' => '70.00', 'is_active' => true]);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $food, [
            'services' => [['fee_id' => $food->id, 'quantity' => 1, 'paid_now' => '0.00', 'meal_plan_id' => $plan->id]],
        ]));

        $response->assertSessionHasErrors();
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
    }
}
