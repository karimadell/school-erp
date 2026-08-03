<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Services\Finance\InvoiceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServicePriceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_foundation_has_academic_year_currency_and_unlimited_history(): void
    {
        $this->assertTrue(Schema::hasColumns('fee_prices', ['academic_year_id', 'currency', 'start_date', 'end_date', 'amount', 'is_active']));
        $year = $this->year('2026/2027', '2026-08-01', '2027-06-30');
        $fee = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => 0, 'is_active' => true]);

        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '100.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => '120.00', 'currency' => 'EGP', 'start_date' => '2027-01-01', 'end_date' => null, 'is_active' => true]);

        $this->assertCount(2, $fee->prices);
        $this->assertSame('EGP', $fee->prices->first()->currency);
    }

    public function test_calculator_resolves_by_academic_year_effective_date_and_active_flag(): void
    {
        $firstYear = $this->year('2025/2026', '2025-08-01', '2026-06-30');
        $secondYear = $this->year('2026/2027', '2026-08-01', '2027-06-30');
        $fee = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => 999, 'is_active' => true]);
        foreach ([
            [$firstYear, '90.00', '2025-08-01', null, true],
            [$secondYear, '100.00', '2026-08-01', '2026-12-31', true],
            [$secondYear, '120.00', '2027-01-01', null, true],
            [$secondYear, '1.00', '2027-01-01', null, false],
        ] as [$year, $amount, $from, $to, $active]) {
            FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => $amount, 'currency' => 'EGP', 'start_date' => $from, 'end_date' => $to, 'is_active' => $active]);
        }

        $calculator = app(InvoiceCalculationService::class);
        $this->assertSame('100.00', $calculator->calculate([['fee_id' => $fee->id]], pricingDate: '2026-09-01', academicYearId: $secondYear->id)['total_amount']);
        $this->assertSame('120.00', $calculator->calculate([['fee_id' => $fee->id]], pricingDate: '2027-02-01', academicYearId: $secondYear->id)['total_amount']);
        $this->assertSame('90.00', $calculator->calculate([['fee_id' => $fee->id]], pricingDate: '2025-09-01', academicYearId: $firstYear->id)['total_amount']);
    }

    public function test_missing_year_price_is_rejected_when_history_exists(): void
    {
        $firstYear = $this->year('2025/2026', '2025-08-01', '2026-06-30');
        $secondYear = $this->year('2026/2027', '2026-08-01', '2027-06-30');
        $fee = Fee::create(['name_ru' => 'Транспорт', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => 999, 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $firstYear->id, 'amount' => 100, 'currency' => 'EGP', 'start_date' => '2025-08-01', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        app(InvoiceCalculationService::class)->calculate([['fee_id' => $fee->id]], pricingDate: '2026-09-01', academicYearId: $secondYear->id);
    }

    public function test_existing_invoice_item_snapshot_does_not_change_with_catalog_price(): void
    {
        $year = $this->year('2026/2027', '2026-08-01', '2027-06-30');
        $fee = Fee::create(['name_ru' => 'Книги', 'category' => Fee::CATEGORY_BOOKS, 'amount' => 0, 'is_active' => true]);
        $price = FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $year->id, 'amount' => 100, 'currency' => 'EGP', 'start_date' => '2026-08-01', 'is_active' => true]);
        $student = Student::create(['name' => 'Иванов Иван']);
        $invoice = Invoice::create(['student_id' => $student->id, 'academic_year_id' => $year->id, 'total_amount' => 100, 'paid_amount' => 0, 'remaining_amount' => 100, 'status' => Invoice::STATUS_UNPAID]);
        $item = InvoiceItem::create(['invoice_id' => $invoice->id, 'fee_id' => $fee->id, 'description' => 'Книги', 'amount' => 100]);

        $price->update(['amount' => 150]);
        $this->assertSame('100.00', $item->fresh()->amount);
        $this->assertSame('100.00', $invoice->fresh()->total_amount);
    }

    private function year(string $name, string $start, string $end): AcademicYear
    {
        return AcademicYear::create(['name' => $name, 'start_date' => $start, 'end_date' => $end, 'is_active' => false]);
    }
}
