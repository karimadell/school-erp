<?php

namespace Tests\Feature\Finance;

use App\Models\EnrollmentMode;
use App\Models\FeePrice;
use App\Models\User;
use App\Services\Finance\InvoiceCalculationService;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard-native Finance pricing workflow corrective: "Цены на услуги"
 * (dashboard.finance.tariffs.*, already existing, already Dashboard-layout,
 * already append-only by route surface) is now directly reachable from the
 * Finance sidebar, and its Tuition create form supports the same
 * EnrollmentMode Study Mode safety Filament's FeePriceResource already has
 * — via the SAME shared TuitionEnrollmentModePricing rule, never a second
 * implementation. Filament's own screen remains fully functional and
 * canonical; nothing about InvoiceCalculationService/pricing resolution is
 * touched here.
 */
class DashboardFinancePricingWorkflowTest extends FinanceOperationsTestCase
{
    // 1. Authorized Finance user sees "Цены на услуги" in the sidebar.
    public function test_authorized_user_sees_service_prices_sidebar_link(): void
    {
        $html = (string) $this->actingAs($this->accountant)->view('layouts.partials.shell-sidebar');

        $this->assertStringContainsString(route('dashboard.finance.tariffs.index'), $html);
        $this->assertStringContainsString('Цены на услуги', $html);
    }

    // 2. Unauthorized user does not see the item.
    public function test_unauthorized_user_does_not_see_service_prices_sidebar_link(): void
    {
        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');

        $html = (string) $this->actingAs($reception)->view('layouts.partials.shell-sidebar');

        $this->assertStringNotContainsString(route('dashboard.finance.tariffs.index'), $html);
    }

    // 3. Dashboard Tuition create form exposes EnrollmentMode options from
    // master data (never hardcoded in Blade).
    public function test_dashboard_create_form_exposes_enrollment_mode_options_from_master_data(): void
    {
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false, 'display_order' => 2]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.create'));

        $response->assertOk();
        $response->assertSee('Форма обучения');
        $response->assertSee('Экстернат');
        $this->assertTrue(EnrollmentMode::where('code', $external->code)->exists());
    }

    // 4. A valid EnrollmentMode code is stored in exactly the
    // representation InvoiceCalculationService's resolver expects.
    public function test_valid_enrollment_mode_persists_exact_representation(): void
    {
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false]);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.finance.tariffs.store'), [
            'fee_id' => $this->fee->id,
            'academic_year_id' => $this->year->id,
            'amount' => '3200.00',
            'start_date' => '2026-08-01',
            'grade_group' => '1–4 классы',
            'payment_period' => 'monthly',
            'option_value' => $external->code,
            'is_active' => '1',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $price = FeePrice::query()->where('fee_id', $this->fee->id)->where('amount', '3200.00')->sole();
        $this->assertSame('enrollment_mode', $price->option_type);
        $this->assertSame('external', $price->option_value);
    }

    // 5. Invalid/free-text EnrollmentMode cannot be persisted.
    public function test_invalid_enrollment_mode_cannot_be_persisted(): void
    {
        $before = FeePrice::count();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.finance.tariffs.store'), [
            'fee_id' => $this->fee->id,
            'academic_year_id' => $this->year->id,
            'amount' => '3200.00',
            'start_date' => '2026-08-01',
            'grade_group' => '1–4 классы',
            'payment_period' => 'monthly',
            'option_value' => 'not_a_real_mode_code',
            'is_active' => '1',
        ]);

        $response->assertSessionHasErrors('option_value');
        $this->assertSame($before, FeePrice::count());
    }

    // 6. Blank EnrollmentMode remains supported: a generic (non-mode-scoped)
    // tariff is still a legitimate, fully-supported request.
    public function test_blank_enrollment_mode_creates_generic_tariff(): void
    {
        $response = $this->actingAs($this->accountant)->post(route('dashboard.finance.tariffs.store'), [
            'fee_id' => $this->fee->id,
            'academic_year_id' => $this->year->id,
            'amount' => '9999.00',
            'start_date' => '2027-01-01',
            'grade_group' => '5–6 классы',
            'payment_period' => 'yearly',
            'is_active' => '1',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $price = FeePrice::query()->where('amount', '9999.00')->sole();
        $this->assertNull($price->option_type);
        $this->assertNull($price->option_value);
    }

    // 10. Invalid prefill query-string values are ignored, never persisted
    // or reflected — same contract as Filament's fillForm().
    public function test_invalid_prefill_values_are_safely_ignored(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.create', [
            'fee_id' => $this->fee->id,
            'academic_year_id' => 999999,
            'grade_id' => 999999,
            'grade_group' => 'bogus group',
            'payment_period' => 'bogus_period',
            'enrollment_mode' => 'bogus_code',
        ]));

        $response->assertOk();
        $response->assertViewHas('selectedAcademicYearId', null);
        $response->assertViewHas('selectedGradeId', null);
        $response->assertViewHas('selectedGradeGroup', null);
        $response->assertViewHas('selectedPaymentPeriod', null);
        $response->assertViewHas('selectedEnrollmentModeCode', null);
    }

    // 10b. Valid prefill values are correctly reflected as defaults.
    public function test_valid_prefill_values_are_reflected(): void
    {
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.create', [
            'fee_id' => $this->fee->id,
            'academic_year_id' => $this->year->id,
            'grade_group' => '5–6 классы',
            'payment_period' => 'monthly',
            'enrollment_mode' => $external->code,
        ]));

        $response->assertOk();
        $response->assertViewHas('selectedAcademicYearId', $this->year->id);
        $response->assertViewHas('selectedGradeGroup', '5–6 классы');
        $response->assertViewHas('selectedPaymentPeriod', 'monthly');
        $response->assertViewHas('selectedEnrollmentModeCode', 'external');
        // Amount is never prefilled — the field must render empty.
        $response->assertSee('name="amount" value="" inputmode="decimal"', false);
    }

    // 12. Append-only route surface unchanged — no edit/update/destroy
    // tariff routes exist at all.
    public function test_no_edit_update_destroy_tariff_routes_exist(): void
    {
        $this->assertFalse(Route::has('dashboard.finance.tariffs.edit'));
        $this->assertFalse(Route::has('dashboard.finance.tariffs.update'));
        $this->assertFalse(Route::has('dashboard.finance.tariffs.destroy'));
    }

    // 13. A mode-scoped price created through this Dashboard screen resolves
    // exactly like one created through Filament, and External 5–6 (never
    // configured) still fails loud with no fallback.
    public function test_created_mode_scoped_price_resolves_correctly_and_external_5_6_stays_missing(): void
    {
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false]);

        $this->actingAs($this->accountant)->post(route('dashboard.finance.tariffs.store'), [
            'fee_id' => $this->fee->id,
            'academic_year_id' => $this->year->id,
            'amount' => '3200.00',
            'start_date' => '2026-08-01',
            'grade_group' => '1–4 классы',
            'payment_period' => 'monthly',
            'option_value' => $external->code,
            'is_active' => '1',
        ])->assertSessionDoesntHaveErrors();

        $calculator = app(InvoiceCalculationService::class);
        $calculation = $calculator->calculate(
            items: [['fee_id' => $this->fee->id, 'grade_group' => '1–4 классы', 'payment_period' => 'monthly', 'enrollment_mode_id' => $external->id, 'quantity' => 1]],
            pricingDate: '2026-09-10',
            academicYearId: $this->year->id,
        );
        $this->assertSame('3200.00', $calculation['line_items'][0]['unit_price']);

        $this->expectException(ValidationException::class);
        $calculator->calculate(
            items: [['fee_id' => $this->fee->id, 'grade_group' => '5–6 классы', 'payment_period' => 'monthly', 'enrollment_mode_id' => $external->id, 'quantity' => 1]],
            pricingDate: '2026-09-10',
            academicYearId: $this->year->id,
        );
    }
}
