<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Services\Finance\InvoiceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceCalculationTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InvoiceCalculationService::class);
    }

    private function fee(string $amount = '100.00'): Fee
    {
        return Fee::create(['name_ru' => 'Обучение', 'amount' => $amount, 'is_active' => true]);
    }

    public function test_price_is_resolved_from_database_and_browser_totals_are_not_inputs(): void
    {
        $fee = $this->fee('100.00');
        FeePrice::create(['fee_id' => $fee->id, 'amount' => '125.50', 'start_date' => '2026-01-01', 'is_active' => true]);

        $result = $this->service->calculate([['fee_id' => $fee->id]], pricingDate: '2026-08-01');

        $this->assertSame('125.50', $result['subtotal']);
        $this->assertSame('125.50', $result['total_amount']);
        $this->assertArrayNotHasKey('browser_total', $result);
    }

    public function test_subtotal_and_percentage_discount_are_exact_to_two_decimals(): void
    {
        $first = $this->fee('10.10');
        $second = Fee::create(['name_ru' => 'Питание', 'amount' => '20.20', 'is_active' => true]);

        $result = $this->service->calculate(
            [['fee_id' => $first->id], ['fee_id' => $second->id]],
            discountType: 'percent',
            discountValue: '10.00',
        );

        $this->assertSame('30.30', $result['subtotal']);
        $this->assertSame('3.03', $result['discount_amount']);
        $this->assertSame('27.27', $result['total_amount']);
    }

    public function test_percentage_discount_uses_commercial_rounding(): void
    {
        $result = $this->service->calculate(
            [['fee_id' => $this->fee('10.05')->id]],
            discountType: 'percent',
            discountValue: '10.00',
        );

        $this->assertSame('1.01', $result['discount_amount']);
        $this->assertSame('9.04', $result['total_amount']);
    }

    #[DataProvider('invalidDiscountProvider')]
    public function test_invalid_discounts_are_rejected(string $type, string $value, string $message): void
    {
        $fee = $this->fee();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);

        $this->service->calculate([['fee_id' => $fee->id]], $type, $value);
    }

    public static function invalidDiscountProvider(): array
    {
        return [
            'percent above 100' => ['percent', '100.01', 'Процентная скидка не может превышать 100%.'],
            'negative percent' => ['percent', '-0.01', 'Скидка не может быть отрицательной.'],
            'fixed above subtotal' => ['fixed', '100.01', 'Фиксированная скидка не может превышать сумму услуг.'],
        ];
    }

    public function test_omitted_initial_payment_is_zero_and_status_is_unpaid(): void
    {
        $result = $this->service->calculate([['fee_id' => $this->fee()->id]]);

        $this->assertSame('0.00', $result['paid_amount']);
        $this->assertSame('100.00', $result['remaining_amount']);
        $this->assertSame(Invoice::STATUS_UNPAID, $result['status']);
    }

    #[DataProvider('invalidPaymentProvider')]
    public function test_invalid_initial_payments_are_rejected(string $payment): void
    {
        $this->expectException(ValidationException::class);
        $this->service->calculate([['fee_id' => $this->fee()->id]], initialPaymentAmount: $payment);
    }

    public static function invalidPaymentProvider(): array
    {
        return [['-0.01'], ['100.01']];
    }

    #[DataProvider('statusProvider')]
    public function test_status_is_derived_from_payment(string $payment, string $status): void
    {
        $result = $this->service->calculate([['fee_id' => $this->fee()->id]], initialPaymentAmount: $payment);
        $this->assertSame($status, $result['status']);
    }

    public static function statusProvider(): array
    {
        return [
            ['0.00', Invoice::STATUS_UNPAID],
            ['50.00', Invoice::STATUS_PARTIAL],
            ['100.00', Invoice::STATUS_PAID],
        ];
    }

    public function test_total_never_becomes_negative_and_currency_is_egp(): void
    {
        $result = $this->service->calculate([['fee_id' => $this->fee()->id]], 'percent', '100.00');

        $this->assertSame('0.00', $result['total_amount']);
        $this->assertSame('EGP', $result['currency']);
    }
}
