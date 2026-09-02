<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\ServiceCoverage;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\TariffAdjustmentService;

/**
 * Finance V2, Phase 2D item 6 (docs/finance-v2-architecture.md) — minimal
 * student finance statement visibility for tariff adjustments. The
 * "Тарифные корректировки" tab already existed (fee, kind, total
 * difference, segment date range) — this only adds old/new tariff and the
 * effective/approval date to that same existing section, no new page.
 */
class StudentFinanceTariffAdjustmentVisibilityTest extends FinanceOperationsTestCase
{
    public function test_student_finance_page_shows_old_new_tariff_and_effective_date_for_an_adjustment(): void
    {
        $fee = Fee::create(['name_ru' => 'Трансфер (видимость)', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2026-12-31', 'is_active' => true]);

        app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Зона 1']],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $this->accountant);

        $increase = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Зона 1', 'amount' => '650.00', 'currency' => 'EGP', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $coverage = ServiceCoverage::where('fee_id', $fee->id)->sole();
        app(TariffAdjustmentService::class)->approve($coverage->fresh(), $increase, $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student));

        $response->assertOk()
            ->assertSee('Трансфер (видимость)')
            ->assertSee('500.00')
            ->assertSee('650.00')
            ->assertSee('применено');
    }
}
