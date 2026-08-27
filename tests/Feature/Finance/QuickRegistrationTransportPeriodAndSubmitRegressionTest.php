<?php

namespace Tests\Feature\Finance;

use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * UAT bugs 2 and 3 (Transport missing payment_period → silent submit block).
 *
 * Root cause (confirmed by code inspection, not guessed):
 *  - Bug 2: the Quick Registration Transport fields never rendered a
 *    payment_period selector, yet InvoiceCalculationService::resolvePrice()
 *    already required one whenever the fee has any payment_period-dimensioned
 *    tariff row (`$fee->prices()->whereNotNull('payment_period')->exists()`)
 *    — exactly UAT's real zone tariffs (monthly + yearly per zone).
 *  - Bug 3: the form's submit handler called event.preventDefault() with NO
 *    visible feedback whenever any checked row's price failed to resolve —
 *    which Bug 2 made permanent for Transport, producing a "nothing happens"
 *    click with no request, no navigation, no console error.
 *
 * Both fixes are covered here at the level Pest/PHPUnit can actually verify
 * (server-side validation, resolved pricing, persisted records, and the
 * static HTML structure of the rendered form). The purely client-side
 * behaviors — automatic price initialization on load (Bug 1, via the new
 * 'pageshow' listener) and the on-submit scroll/highlight (Bug 3) — cannot
 * be exercised by these tests, since there is no headless-browser tooling
 * in this project; those still need a manual/browser check before considering
 * Bug 1 and Bug 3's UX fully verified.
 */
class QuickRegistrationTransportPeriodAndSubmitRegressionTest extends QuickRegistrationUxTestCase
{
    private function transportFeeWithPeriods(array $structure): Fee
    {
        [$year] = $structure;
        $fee = $this->fee('Транспорт', Fee::CATEGORY_TRANSPORT);
        foreach (['Зона 1' => ['monthly' => '1500.00', 'yearly' => '13500.00'], 'Зона 2' => ['monthly' => '1800.00', 'yearly' => '16200.00']] as $zone => $amounts) {
            foreach ($amounts as $period => $amount) {
                FeePrice::create([
                    'fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => $amount, 'currency' => 'EGP',
                    'start_date' => $year->start_date, 'end_date' => $year->end_date, 'is_active' => true,
                    'option_type' => 'zone', 'option_value' => $zone, 'payment_period' => $period,
                ]);
            }
        }

        return $fee;
    }

    private function route(): int
    {
        return DB::table('transport_routes')->insertGetId(['name' => 'Маршрут 1', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ----- 3. zone → only valid payment periods appear (server-rendered data) ----

    public function test_the_transport_period_dropdown_is_derived_from_live_fee_price_rows_per_zone(): void
    {
        $structure = $this->structure();
        $fee = $this->transportFeeWithPeriods($structure);

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk()->getContent();

        // Blade escapes the JSON attribute (") to &quot; and toJson() unicode-
        // escapes Cyrillic — assert on the actual encoded form, not raw UTF-8/JSON.
        $this->assertStringContainsString('data-periods-by-zone=', $html);
        $this->assertStringContainsString('Зона 1', $html); // "Зона 1"
        $this->assertStringContainsString('&quot;monthly&quot;', $html);
        $this->assertStringContainsString('&quot;yearly&quot;', $html);
        $this->assertStringContainsString('transport-period', $html);
        // The periods actually offered per zone come only from this fee's own
        // FeePrice rows (periodLabels itself is a static, page-wide Russian
        // label dictionary shared with Tuition — its presence is not
        // "hardcoded pricing/periods"; only the per-zone JSON payload below
        // governs the dropdown's actual options).
        $attrStart = strpos($html, 'data-periods-by-zone="');
        $this->assertNotFalse($attrStart);
        $attrValueStart = $attrStart + strlen('data-periods-by-zone="');
        $attrEnd = strpos($html, '"', $attrValueStart);
        $periodsByZoneJson = substr($html, $attrValueStart, $attrEnd - $attrValueStart);
        $this->assertStringNotContainsString('quarterly', $periodsByZoneJson);
        $this->assertStringContainsString('monthly', $periodsByZoneJson);
        $this->assertStringContainsString('yearly', $periodsByZoneJson);
    }

    public function test_the_period_label_is_optional_when_the_fee_has_no_period_dimensioned_tariff(): void
    {
        $structure = $this->structure();
        $fee = $this->fee('Транспорт', Fee::CATEGORY_TRANSPORT);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $structure[0]->id, 'amount' => '500.00', 'currency' => 'EGP',
            'start_date' => $structure[0]->start_date, 'end_date' => $structure[0]->end_date, 'is_active' => true,
            'option_type' => 'zone', 'option_value' => 'Зона 1',
        ]);

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk()->getContent();

        $this->assertStringContainsString('>Период оплаты<', $html);
        $this->assertStringNotContainsString('>Период оплаты *<', $html);
    }

    // ----- 4. zone + period resolves the correct authoritative amount ------------

    public function test_zone_and_period_resolve_the_correct_authoritative_amount(): void
    {
        $structure = $this->structure();
        [$year, , , , $mode] = $structure;
        $fee = $this->transportFeeWithPeriods($structure);

        $response = $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $fee->id, 'quantity' => 1, 'academic_year_id' => $year->id, 'enrollment_mode_id' => $mode->id,
            'transport_area' => 'Зона 1', 'payment_period' => 'yearly',
        ])->assertOk();

        $this->assertSame('13500.00', $response->json('amount'));
    }

    // ----- 5. a period not valid for the chosen zone is rejected server-side -----

    public function test_a_period_not_configured_for_the_chosen_zone_is_rejected(): void
    {
        $structure = $this->structure();
        [$year, , , , $mode] = $structure;
        // Zone 1 only has monthly/yearly — 'quarterly' does not exist for it.
        $fee = $this->transportFeeWithPeriods($structure);

        $response = $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $fee->id, 'quantity' => 1, 'academic_year_id' => $year->id, 'enrollment_mode_id' => $mode->id,
            'transport_area' => 'Зона 1', 'payment_period' => 'quarterly',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('тариф не настроен', mb_strtolower($response->json('errors.fees.0')));
    }

    // ----- Store-level: valid submission succeeds and resolves correctly ----------

    public function test_transport_with_zone_route_and_period_submits_and_resolves_the_correct_amount(): void
    {
        $structure = $this->structure();
        $fee = $this->transportFeeWithPeriods($structure);
        $routeId = $this->route();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [[
                'fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00',
                'transport_area' => 'Зона 1', 'transport_route_id' => $routeId, 'payment_period' => 'monthly',
            ]],
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $invoice = Invoice::sole();
        $this->assertSame('1500.00', $invoice->total_amount);
        $item = InvoiceItem::sole();
        $this->assertSame('monthly', $item->metadata['payment_period'] ?? null);
        $subscription = StudentServiceSubscription::sole();
        $this->assertSame('monthly', $subscription->metadata['payment_period'] ?? null);
    }

    // ----- Store-level: missing payment_period is rejected with the exact message -

    public function test_missing_payment_period_is_rejected_with_a_visible_russian_message_when_the_tariff_requires_it(): void
    {
        $structure = $this->structure();
        $fee = $this->transportFeeWithPeriods($structure);
        $routeId = $this->route();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [[
                'fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00',
                'transport_area' => 'Зона 1', 'transport_route_id' => $routeId,
                // payment_period deliberately omitted.
            ]],
        ]));

        $response->assertSessionHasErrors(['services.0.payment_period' => 'Для транспорта выберите период оплаты.']);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_payment_period_is_not_required_when_the_fee_has_no_period_dimensioned_tariff(): void
    {
        $structure = $this->structure();
        $fee = $this->fee('Транспорт', Fee::CATEGORY_TRANSPORT);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $structure[0]->id, 'amount' => '500.00', 'currency' => 'EGP',
            'start_date' => $structure[0]->start_date, 'end_date' => $structure[0]->end_date, 'is_active' => true,
            'option_type' => 'zone', 'option_value' => 'Зона 1',
        ]);
        $routeId = $this->route();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00', 'transport_area' => 'Зона 1', 'transport_route_id' => $routeId]],
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('500.00', Invoice::sole()->total_amount);
    }

    // ----- 6. unselected Transport submits no transport pricing fields -------------

    public function test_a_transport_service_absent_from_the_payload_requires_nothing_and_leaks_nothing(): void
    {
        $structure = $this->structure();
        $this->transportFeeWithPeriods($structure);
        $registration = $this->fee();

        // Transport is simply never present in the services array — exactly
        // what an unchecked (and therefore disabled, per updateRow()) row
        // submits, since disabled fields never serialize.
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $registration));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, InvoiceItem::count());
        $this->assertDatabaseCount('student_service_subscriptions', 1);
    }

    // ----- 7. valid POST creates exactly the expected records ----------------------

    public function test_a_valid_multi_service_submission_creates_exactly_the_expected_records(): void
    {
        $structure = $this->structure();
        $registration = $this->fee();
        $transport = $this->transportFeeWithPeriods($structure);
        $routeId = $this->route();
        $account = \App\Models\CashAccount::operating();
        app(\App\Services\Finance\CashSessionService::class)->open($account, $this->accountant);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $registration, [
            'services' => [
                ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '1000.00'],
                ['fee_id' => $transport->id, 'quantity' => 1, 'paid_now' => '0.00', 'transport_area' => 'Зона 1', 'transport_route_id' => $routeId, 'payment_period' => 'monthly'],
            ],
            'cash_account_id' => $account->id, 'payment_method' => 'cash',
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Student::count());
        $this->assertSame(1, Enrollment::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(2, InvoiceItem::count());
        $this->assertSame(2, StudentServiceSubscription::count());
        $this->assertGreaterThanOrEqual(1, InvoiceInstallment::count());
        $this->assertSame(1, InvoicePayment::count());
        $this->assertSame('1000.00', InvoicePayment::sole()->amount);
        $this->assertSame('2500.00', Invoice::sole()->total_amount);
    }

    // ----- 8. no required hidden/disabled field can silently block a valid submit --

    public function test_no_service_field_carries_a_native_required_attribute(): void
    {
        $structure = $this->structure();
        $this->transportFeeWithPeriods($structure);
        $this->fee();

        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk()->getContent();

        $servicesSectionStart = strpos($html, '2. Финансовые услуги');
        $servicesSectionEnd = strpos($html, '3. Финансовый итог');
        $this->assertNotFalse($servicesSectionStart);
        $this->assertNotFalse($servicesSectionEnd);
        $servicesSection = substr($html, $servicesSectionStart, $servicesSectionEnd - $servicesSectionStart);

        // Every field inside a service row is toggled enabled/disabled purely
        // by JS (updateRow) based on the checkbox — none may carry a native
        // `required` attribute, which would make an unchecked-but-still-
        // enabled-at-parse-time field block native HTML5 submission silently.
        $this->assertDoesNotMatchRegularExpression('/<(select|input)(?![^>]*type="checkbox")[^>]*\brequired\b/', $servicesSection);
    }

    public function test_the_submit_button_is_a_real_submit_control_inside_the_form(): void
    {
        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk()->getContent();

        $formStart = strpos($html, '<form method="POST" action="'.route('dashboard.quick-registration.store'));
        $buttonPos = strpos($html, 'Создать ученика и счёт');
        $formEnd = strpos($html, '</form>', $formStart);
        $this->assertNotFalse($formStart);
        $this->assertNotFalse($buttonPos);
        $this->assertTrue($formStart < $buttonPos && $buttonPos < $formEnd, 'the submit button must be inside the <form>, so a default (implicit submit) button type actually submits it');
        // No explicit type="button"/type="reset" on the submit control.
        $buttonTagStart = strrpos(substr($html, 0, $buttonPos), '<button');
        $buttonTag = substr($html, $buttonTagStart, $buttonPos - $buttonTagStart);
        $this->assertStringNotContainsString('type="button"', $buttonTag);
        $this->assertStringNotContainsString('type="reset"', $buttonTag);
    }

    // ----- Structural signal that the Bug 1 fix (pageshow-based init) is present ---

    public function test_the_page_initializes_pricing_via_pageshow_not_only_at_parse_time(): void
    {
        $html = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'))->assertOk()->getContent();

        $this->assertStringContainsString("addEventListener('pageshow'", $html);
    }
}
