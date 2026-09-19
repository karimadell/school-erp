<?php

namespace Tests\Feature\Finance;

use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;

/**
 * Confirmed UAT eligibility gap corrective: Dashboard tariff creation
 * (dashboard.finance.tariffs.create/store) now reuses the SAME
 * NewSaleFeePolicy Quick Registration already uses, so a legacy Tuition
 * Fee (tuition_regular/tuition_family/tuition_external) can never be
 * selected — in the browser or via a crafted request — as the target of a
 * NEW tariff. Historical FeePrice rows already tied to a legacy Fee, and
 * the index/history browsing surface, are entirely untouched. Quick
 * Registration's own policy/behavior is not modified.
 */
class DashboardTariffNewSaleFeeEligibilityTest extends FinanceOperationsTestCase
{
    private function legacyFee(string $category, string $name): Fee
    {
        return Fee::create(['name_ru' => $name, 'category' => $category, 'amount' => '0.00', 'is_active' => true]);
    }

    // 1. Unified Tuition appears in the Dashboard new-tariff service selector.
    public function test_unified_tuition_appears_in_service_selector(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.create'));

        $response->assertOk();
        $response->assertViewHas('services', fn ($services) => $services->contains('id', $this->fee->id));
    }

    // 2, 3, 4. Legacy Tuition categories do not appear.
    public function test_legacy_tuition_categories_do_not_appear_in_service_selector(): void
    {
        $regular = $this->legacyFee(Fee::CATEGORY_TUITION_REGULAR, 'Обучение (обычное)');
        $family = $this->legacyFee(Fee::CATEGORY_TUITION_FAMILY, 'Обучение (семейное)');
        $external = $this->legacyFee(Fee::CATEGORY_TUITION_EXTERNAL, 'ЭКСТЕРНАТ');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.create'));

        $response->assertOk();
        $ids = $response->viewData('services')->pluck('id');
        $this->assertFalse($ids->contains($regular->id));
        $this->assertFalse($ids->contains($family->id));
        $this->assertFalse($ids->contains($external->id));
        $response->assertDontSee('ЭКСТЕРНАТ');
    }

    // 5. Transport/Food/Uniform/Registration remain available when otherwise active.
    public function test_other_categories_remain_available(): void
    {
        $transport = Fee::create(['name_ru' => 'Транспорт', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '0.00', 'is_active' => true]);
        $food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '0.00', 'is_active' => true]);
        $uniform = Fee::create(['name_ru' => 'Форма', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '0.00', 'is_active' => true]);
        $registration = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '0.00', 'is_active' => true]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.create'));

        $ids = $response->viewData('services')->pluck('id');
        $this->assertTrue($ids->contains($transport->id));
        $this->assertTrue($ids->contains($food->id));
        $this->assertTrue($ids->contains($uniform->id));
        $this->assertTrue($ids->contains($registration->id));
    }

    // 6. Activity remains available here — this is NOT Quick Registration policy.
    public function test_activity_remains_available(): void
    {
        $activity = Fee::create(['name_ru' => 'Мероприятие', 'category' => Fee::CATEGORY_ACTIVITY, 'amount' => '0.00', 'is_active' => true]);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.create'));

        $ids = $response->viewData('services')->pluck('id');
        $this->assertTrue($ids->contains($activity->id));
    }

    // 7 & 8. A crafted POST for a legacy Tuition Fee is rejected server-side
    // and creates zero FeePrice rows.
    public function test_crafted_post_for_legacy_tuition_fee_is_rejected_and_creates_nothing(): void
    {
        $external = $this->legacyFee(Fee::CATEGORY_TUITION_EXTERNAL, 'ЭКСТЕРНАТ');
        $before = FeePrice::count();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.finance.tariffs.store'), [
            'fee_id' => $external->id,
            'academic_year_id' => $this->year->id,
            'amount' => '3200.00',
            'start_date' => '2026-08-01',
            'grade_group' => '1–4 классы',
            'payment_period' => 'monthly',
            'is_active' => '1',
        ]);

        $response->assertSessionHasErrors('fee_id');
        $this->assertSame($before, FeePrice::count());
    }

    // 9. Unified Tuition tariff creation still works with a valid EnrollmentMode.
    public function test_unified_tuition_tariff_creation_still_works(): void
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

    // 10. A legacy fee_id query-string prefill cannot select a legacy Fee
    // for new tariff creation.
    public function test_legacy_fee_id_prefill_is_dropped(): void
    {
        $external = $this->legacyFee(Fee::CATEGORY_TUITION_EXTERNAL, 'ЭКСТЕРНАТ');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.create', ['fee_id' => $external->id]));

        $response->assertOk();
        $response->assertViewHas('selectedFeeId', null);
    }

    // Unified Tuition fee_id continues to prefill normally.
    public function test_unified_tuition_fee_id_prefill_still_works(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.create', ['fee_id' => $this->fee->id]));

        $response->assertOk();
        $response->assertViewHas('selectedFeeId', $this->fee->id);
    }

    // 11. Historical FeePrice rows tied to a legacy Fee remain
    // visible/readable in tariff index/show.
    public function test_historical_legacy_fee_price_remains_visible_in_index_and_show(): void
    {
        $external = $this->legacyFee(Fee::CATEGORY_TUITION_EXTERNAL, 'ЭКСТЕРНАТ');
        $historicalPrice = FeePrice::create([
            'fee_id' => $external->id, 'academic_year_id' => $this->year->id, 'amount' => '25600.00',
            'currency' => 'EGP', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => true,
        ]);

        $indexResponse = $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.index'));
        $indexResponse->assertOk();
        $this->assertTrue($indexResponse->viewData('tariffs')->contains('id', $historicalPrice->id));
        // The index's own Fee filter dropdown may legitimately still list
        // legacy Fees so historical records stay discoverable/filterable.
        $this->assertTrue($indexResponse->viewData('services')->contains('id', $external->id));

        $showResponse = $this->actingAs($this->accountant)->get(route('dashboard.finance.tariffs.show', $historicalPrice));
        $showResponse->assertOk();
        $showResponse->assertSee('ЭКСТЕРНАТ');
    }

    // 12. Existing Quick Registration behavior remains unchanged — it
    // already excluded legacy Tuition categories via its own
    // QuickRegistrationFeePolicy, untouched here.
    public function test_quick_registration_service_list_remains_unchanged(): void
    {
        $external = $this->legacyFee(Fee::CATEGORY_TUITION_EXTERNAL, 'ЭКСТЕРНАТ');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));

        $response->assertOk();
        $response->assertDontSee('ЭКСТЕРНАТ');
    }
}
