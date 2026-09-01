<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Services\Finance\InvoiceCalculationService;
use Illuminate\Validation\ValidationException;

/**
 * Finance V2, Phase 2D item 1 (docs/finance-v2-architecture.md).
 *
 * Quarterly derived pricing: an explicit quarterly FeePrice always wins;
 * otherwise the resolver derives monthly x 3, marks the result as derived
 * (never a real FeePrice row), and never falls back further (no yearly, no
 * Fee.amount/base_price). Gated on the Fee actually allowing quarterly
 * billing at all.
 */
class QuarterlyDerivedPricingTest extends FinanceOperationsTestCase
{
    private function tuitionFee(): Fee
    {
        $fee = Fee::create(['name_ru' => 'Обучение (квартал)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'quarterly']);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);

        return $fee;
    }

    private function calculate(Fee $fee, array $selection, string $date = '2026-09-01'): array
    {
        return app(InvoiceCalculationService::class)->calculate(
            [['fee_id' => $fee->id] + $selection],
            null, null, '0', $date, $this->year->id,
        );
    }

    public function test_explicit_quarterly_price_wins_over_derivation(): void
    {
        $fee = $this->tuitionFee();
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'quarterly', 'amount' => '2700.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $result = $this->calculate($fee, ['payment_period' => 'quarterly']);

        $this->assertSame('2700.00', $result['line_items'][0]['amount']);
        $this->assertArrayNotHasKey('derived', $result['line_items'][0]['metadata']);
    }

    public function test_missing_quarterly_derives_monthly_times_three(): void
    {
        $fee = $this->tuitionFee();
        $monthly = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $result = $this->calculate($fee, ['payment_period' => 'quarterly']);
        $line = $result['line_items'][0];

        $this->assertSame('3000.00', $line['amount']);
        $this->assertTrue($line['metadata']['derived']);
        $this->assertSame('quarterly', $line['metadata']['derived_period']);
        $this->assertSame('monthly', $line['metadata']['derived_from_period']);
        $this->assertSame($monthly->id, $line['metadata']['derived_from_fee_price_id']);
        $this->assertSame('1000.00', $line['metadata']['monthly_unit_amount']);
        $this->assertSame('3', $line['metadata']['multiplier']);
    }

    public function test_missing_quarterly_and_missing_monthly_fails_loud(): void
    {
        $fee = $this->tuitionFee();
        // No FeePrice at all for this fee.
        $this->expectException(ValidationException::class);
        $this->calculate($fee, ['payment_period' => 'quarterly']);
    }

    public function test_derivation_never_falls_back_to_yearly_or_flat_fee_amount(): void
    {
        $fee = $this->tuitionFee();
        // A yearly price exists, but no monthly one — derivation must not
        // silently use the yearly tariff or Fee.amount as a substitute.
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'yearly', 'amount' => '9999.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        $this->calculate($fee, ['payment_period' => 'quarterly']);
    }

    public function test_derivation_respects_other_tariff_dimensions(): void
    {
        // Two zones (Transport-shaped dimension via option_type/option_value),
        // each with their own monthly price — derivation must never
        // cross-contaminate between them.
        $transport = Fee::create(['name_ru' => 'Трансфер', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $transport->billingPeriods()->create(['billing_period' => 'quarterly']);
        FeePrice::create(['fee_id' => $transport->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        FeePrice::create(['fee_id' => $transport->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 2', 'amount' => '800.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $zone1 = $this->calculate($transport, ['payment_period' => 'quarterly', 'option_type' => 'zone', 'option_value' => 'Зона 1']);
        $zone2 = $this->calculate($transport, ['payment_period' => 'quarterly', 'option_type' => 'zone', 'option_value' => 'Зона 2']);

        $this->assertSame('1500.00', $zone1['line_items'][0]['amount']);
        $this->assertSame('2400.00', $zone2['line_items'][0]['amount']);
    }

    public function test_derivation_respects_effective_dates(): void
    {
        $fee = $this->tuitionFee();
        // Two successive monthly tariffs, a mid-year increase.
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $increased = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'amount' => '1200.00', 'currency' => 'EGP', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $before = $this->calculate($fee, ['payment_period' => 'quarterly'], '2026-10-01');
        $after = $this->calculate($fee, ['payment_period' => 'quarterly'], '2027-02-01');

        $this->assertSame('3000.00', $before['line_items'][0]['amount'], 'derives from the tariff valid as of the earlier pricing date');
        $this->assertSame('3600.00', $after['line_items'][0]['amount'], 'derives from the tariff valid as of the later pricing date');
        $this->assertSame($increased->id, $after['line_items'][0]['metadata']['derived_from_fee_price_id']);
    }

    public function test_fee_not_allowing_quarterly_never_triggers_derivation(): void
    {
        $fee = Fee::create(['name_ru' => 'Обучение (без квартала)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        // No 'quarterly' in allowed periods.
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        // A monthly price exists and could be derived from, but this Fee
        // is not configured to allow quarterly billing at all — must still
        // fail loud, never silently derive.
        $this->expectException(ValidationException::class);
        $this->calculate($fee, ['payment_period' => 'quarterly']);
    }

    public function test_no_derivation_ever_creates_a_real_fee_price_row(): void
    {
        $fee = $this->tuitionFee();
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $countBefore = FeePrice::count();
        $this->calculate($fee, ['payment_period' => 'quarterly']);
        $this->calculate($fee, ['payment_period' => 'quarterly']);

        $this->assertSame($countBefore, FeePrice::count(), 'derivation is computed at resolution time only, never persisted');
    }
}
