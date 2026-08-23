<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ServiceCoverage;
use App\Services\Finance\ServiceCoverageService;
use Illuminate\Validation\ValidationException;

class ServiceCoverageSelectorTest extends FinanceOperationsTestCase
{
    public function test_first_safe_coverage_source_renders_its_canonical_tariff_only_without_creating_records(): void
    {
        [$invoice, $item, $price] = $this->safeMonthlyItem();
        $otherFee = Fee::create(['name_ru' => 'Посторонняя услуга', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $wrongPrice = $this->monthlyPrice($otherFee, '9999.00', 'Зона 9');
        $before = [ServiceCoverage::count(), Invoice::count(), InvoiceItem::count()];

        $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student))
            ->assertOk()
            ->assertSee('Исходный счёт / позиция')
            ->assertSee($invoice->display_number)
            ->assertSee('#'.$item->id)
            ->assertSee('#'.$price->id.' · 1500.00 EGP')
            ->assertSee('Зона 1')
            ->assertSee('name="fee_price_id" value="'.$price->id.'"', false)
            ->assertDontSee('name="fee_price_id" value="'.$wrongPrice->id.'"', false);

        $this->assertSame($before, [ServiceCoverage::count(), Invoice::count(), InvoiceItem::count()]);
        $this->assertDatabaseCount('tariff_adjustments', 0);
        $this->assertDatabaseCount('student_credits', 0);
    }

    public function test_unsafe_legacy_item_without_tariff_provenance_has_no_submission_form_and_is_rejected_by_service(): void
    {
        $invoice = $this->invoice('1500.00');
        $item = $invoice->items()->sole();
        $price = $this->monthlyPrice($this->fee, '1500.00', 'Зона 1');

        $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student))
            ->assertOk()
            ->assertSee('Покрытие нельзя создать безопасно')
            ->assertSee('Позиция счёта не содержит подтверждённую ссылку на исходный тариф')
            ->assertDontSee('name="invoice_item_id" value="'.$item->id.'"', false);

        $this->post(route('dashboard.students.finance.coverages.store', $this->student), [
            'invoice_item_id' => $item->id,
            'fee_price_id' => $price->id,
            'coverage_start' => '2026-09-01',
            'coverage_end' => '2027-05-31',
            'billing_unit' => 'monthly',
        ])->assertSessionHasErrors('invoice_item_id');

        try {
            app(ServiceCoverageService::class)->record($item, [
                'fee_price_id' => $price->id,
                'coverage_start' => '2026-09-01',
                'coverage_end' => '2027-05-31',
                'billing_unit' => 'monthly',
            ], $this->accountant, $this->student);
            $this->fail('Legacy items without canonical tariff provenance must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice_item_id', $exception->errors());
        }

        $this->assertDatabaseCount('service_coverages', 0);
    }

    public function test_snapshot_tariff_must_match_service_academic_year_dimensions_and_unit_price(): void
    {
        [, $item, $price] = $this->safeMonthlyItem();
        $service = app(ServiceCoverageService::class);
        $valid = [
            'fee_price_id' => $price->id,
            'coverage_start' => '2026-09-01',
            'coverage_end' => '2027-05-31',
            'billing_unit' => 'monthly',
        ];

        $otherFee = Fee::create(['name_ru' => 'Другая услуга', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $wrongService = $this->monthlyPrice($otherFee, '1500.00', 'Зона 1');
        $otherYear = AcademicYear::create(['name' => '2027/2028', 'start_date' => '2027-07-01', 'end_date' => '2028-06-30', 'is_active' => false]);
        $wrongYear = FeePrice::create([
            'fee_id' => $item->fee_id,
            'academic_year_id' => $otherYear->id,
            'amount' => '1500.00',
            'currency' => 'EGP',
            'start_date' => '2027-07-01',
            'end_date' => '2028-06-30',
            'payment_period' => 'monthly',
            'option_type' => 'zone',
            'option_value' => 'Зона 1',
            'is_active' => true,
        ]);
        foreach ([
            ['metadata' => array_merge($item->metadata, ['fee_price_id' => $wrongService->id])],
            ['metadata' => array_merge($item->metadata, ['fee_price_id' => $wrongYear->id])],
            ['metadata' => array_merge($item->metadata, ['option_value' => 'Зона 2'])],
            ['unit_price' => '1499.00'],
        ] as $mutation) {
            $item->forceFill($mutation)->save();
            try {
                $service->record($item->fresh(), $valid, $this->accountant, $this->student);
                $this->fail('Conflicting source provenance must be rejected.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('service_coverages', 0);
            }
            $item->forceFill(['unit_price' => '1500.00', 'metadata' => [
                'fee_price_id' => $price->id,
                'payment_period' => 'monthly',
                'option_type' => 'zone',
                'option_value' => 'Зона 1',
            ]])->save();
        }

        $coverage = $service->record($item->fresh(), $valid, $this->accountant, $this->student);
        $this->assertSame($price->id, $coverage->fee_price_id);
    }

    public function test_student_with_existing_coverage_still_renders_and_does_not_offer_covered_item_again(): void
    {
        [, $item, $price] = $this->safeMonthlyItem();
        app(ServiceCoverageService::class)->record($item, [
            'fee_price_id' => $price->id,
            'coverage_start' => '2026-09-01',
            'coverage_end' => '2027-05-31',
            'billing_unit' => 'monthly',
        ], $this->accountant, $this->student);

        $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student))
            ->assertOk()
            ->assertSee('ПОКРЫТИЕ')
            ->assertSee('Зона 1')
            ->assertDontSee('name="invoice_item_id" value="'.$item->id.'"', false);

        $this->assertDatabaseCount('service_coverages', 1);
    }

    private function safeMonthlyItem(): array
    {
        $transport = Fee::create(['name_ru' => 'Трансфер', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $price = $this->monthlyPrice($transport, '1500.00', 'Зона 1');
        $invoice = Invoice::create([
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'customer_name' => $this->student->full_name,
            'currency' => 'EGP',
            'subtotal_amount' => '13500.00',
            'total_amount' => '13500.00',
            'discount_amount' => '0.00',
            'paid_amount' => '0.00',
            'remaining_amount' => '13500.00',
            'status' => Invoice::STATUS_UNPAID,
            'due_date' => '2026-09-01',
            'created_by' => $this->accountant->id,
        ]);
        $invoice->forceFill(['invoice_number' => Invoice::numberFor($invoice->id, '2026')])->save();
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'fee_id' => $transport->id,
            'description' => 'Трансфер · Зона 1',
            'unit_price' => '1500.00',
            'quantity' => 9,
            'amount' => '13500.00',
            'paid_amount' => '0.00',
            'remaining_amount' => '13500.00',
            'metadata' => [
                'fee_price_id' => $price->id,
                'academic_year_id' => $this->year->id,
                'currency' => 'EGP',
                'payment_period' => 'monthly',
                'option_type' => 'zone',
                'option_value' => 'Зона 1',
            ],
        ]);

        return [$invoice, $item, $price];
    }

    private function monthlyPrice(Fee $fee, string $amount, string $zone): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $fee->id,
            'academic_year_id' => $this->year->id,
            'amount' => $amount,
            'currency' => 'EGP',
            'start_date' => '2026-09-01',
            'end_date' => '2027-05-31',
            'payment_period' => 'monthly',
            'option_type' => 'zone',
            'option_value' => $zone,
            'is_active' => true,
        ]);
    }
}
