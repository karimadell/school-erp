<?php

namespace Tests\Feature\Finance;

use App\Models\EnrollmentMode;
use App\Models\FeePrice;
use App\Models\User;

/**
 * Quick Registration missing-tariff UX corrective: the live /price preview
 * (a JSON endpoint, structurally separate from store()'s own already-
 * enriched redirect flow) now carries the same authorized-only Add Price
 * link as an extra response key, without ever replacing the original
 * fail-loud validation message and without ever adding a link for an
 * unrelated validation failure. Reuses MissingTariffGuidanceService,
 * pointing at the Dashboard-native tariff create screen
 * (dashboard.finance.tariffs.create) — not the separate Filament admin
 * layout — exactly as store() already does. Nothing about pricing,
 * resolution, or the create form's own prefill contract is touched here.
 */
class QuickRegistrationMissingTariffLinkTest extends FinanceOperationsTestCase
{
    private function seedRemainingCanonicalModes(): void
    {
        // The base fixture only creates full_time. Quick Registration's
        // own RegistrationEnrollmentModePolicy::assertConfigured() requires
        // all four canonical codes to exist exactly once before it will
        // resolve any of them — otherwise a genuine missing-tariff request
        // would never even reach pricing.
        foreach (['family' => 'Семейная', 'external' => 'Экстернат', 'no_enrollment' => 'Без зачисления'] as $code => $name) {
            EnrollmentMode::create(['code' => $code, 'name_ru' => $name, 'is_active' => $code === 'family']);
        }
    }

    private function externalMode(): EnrollmentMode
    {
        return EnrollmentMode::where('code', 'external')->firstOrFail();
    }

    private function previewPayload(array $overrides = []): array
    {
        return array_merge([
            'fee_id' => $this->fee->id,
            'quantity' => 1,
            'academic_year_id' => $this->year->id,
            'enrollment_mode_id' => $this->externalMode()->id,
            'grade_group' => '5–6 классы',
            'payment_period' => 'monthly',
        ], $overrides);
    }

    // 1 & 3. Authorized user, genuine missing tariff: 422, original message
    // preserved, Add Price link present, and the link never carries an
    // amount or an inferred price.
    public function test_authorized_user_genuine_missing_tariff_gets_link_alongside_original_message(): void
    {
        $this->seedRemainingCanonicalModes();

        $response = $this->actingAs($this->accountant)
            ->postJson(route('dashboard.quick-registration.price'), $this->previewPayload());

        $response->assertStatus(422);
        $response->assertJsonPath('errors.fees.0', 'На выбранную дату тариф не настроен.');
        $link = $response->json('missing_tariff_link');
        $this->assertNotNull($link);
        $this->assertStringStartsWith(route('dashboard.finance.tariffs.create'), $link);
        $this->assertStringNotContainsString('/admin/fee-prices', $link);
        $this->assertStringNotContainsString('amount=', $link);
    }

    // 2. The link prefills exactly the supported, correct context.
    public function test_link_prefills_correct_supported_context(): void
    {
        $this->seedRemainingCanonicalModes();

        $response = $this->actingAs($this->accountant)
            ->postJson(route('dashboard.quick-registration.price'), $this->previewPayload());

        $link = $response->json('missing_tariff_link');
        $this->assertStringContainsString('fee_id='.$this->fee->id, $link);
        $this->assertStringContainsString('academic_year_id='.$this->year->id, $link);
        $this->assertStringContainsString('grade_group='.urlencode('5–6 классы'), $link);
        $this->assertStringContainsString('payment_period=monthly', $link);
        $this->assertStringContainsString('enrollment_mode=external', $link);
    }

    // 4. Unauthorized (no "manage fee prices") user: same useful error, no link.
    public function test_unauthorized_user_gets_no_link(): void
    {
        $this->seedRemainingCanonicalModes();
        $cashier = User::factory()->create(['is_active' => true]);
        $cashier->assignRole('cashier');

        $response = $this->actingAs($cashier)
            ->postJson(route('dashboard.quick-registration.price'), $this->previewPayload());

        $response->assertStatus(422);
        $response->assertJsonPath('errors.fees.0', 'На выбранную дату тариф не настроен.');
        $this->assertArrayNotHasKey('missing_tariff_link', $response->json());
    }

    // 5. Unrelated validation failure (invalid academic_year_id) never
    // gets an Add Price link.
    public function test_unrelated_validation_failure_gets_no_link(): void
    {
        $this->seedRemainingCanonicalModes();

        $response = $this->actingAs($this->accountant)
            ->postJson(route('dashboard.quick-registration.price'), $this->previewPayload(['academic_year_id' => 999999]));

        $response->assertStatus(422);
        $this->assertArrayNotHasKey('missing_tariff_link', $response->json());
    }

    // 6. Existing successful preview remains unchanged.
    public function test_successful_preview_remains_unchanged(): void
    {
        $this->seedRemainingCanonicalModes();

        $response = $this->actingAs($this->accountant)->postJson(route('dashboard.quick-registration.price'), [
            'fee_id' => $this->fee->id,
            'quantity' => 1,
            'academic_year_id' => $this->year->id,
            'enrollment_mode_id' => EnrollmentMode::where('code', 'full_time')->firstOrFail()->id,
            'grade_id' => $this->enrollment->grade_id,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['unit_price', 'amount', 'currency', 'valid_from', 'valid_to']);
        $this->assertArrayNotHasKey('missing_tariff_link', $response->json());
    }

    // 7. Fail-loud/no-fallback External 5–6 behavior is unchanged: a real
    // external price for a DIFFERENT grade group must never be borrowed.
    public function test_external_5_6_never_borrows_another_grade_group_or_mode(): void
    {
        $this->seedRemainingCanonicalModes();
        FeePrice::create([
            'fee_id' => $this->fee->id, 'academic_year_id' => $this->year->id, 'grade_group' => '1–4 классы',
            'payment_period' => 'monthly', 'option_type' => 'enrollment_mode', 'option_value' => 'external',
            'amount' => '3200.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        FeePrice::create([
            'fee_id' => $this->fee->id, 'academic_year_id' => $this->year->id, 'grade_group' => '5–6 классы',
            'payment_period' => 'monthly', 'option_type' => 'enrollment_mode', 'option_value' => 'full_time',
            'amount' => '6500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);

        $response = $this->actingAs($this->accountant)
            ->postJson(route('dashboard.quick-registration.price'), $this->previewPayload());

        $response->assertStatus(422);
        $response->assertJsonPath('errors.fees.0', 'На выбранную дату тариф не настроен.');
    }
}
