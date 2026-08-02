<?php

namespace App\Services\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InvoiceCalculationService
{
    public const CURRENCY = 'EGP';

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function calculate(
        array $items,
        ?string $discountType = null,
        string|int|float|null $discountValue = null,
        string|int|float|null $initialPaymentAmount = null,
        ?string $pricingDate = null,
    ): array {
        $pricingDate ??= now()->toDateString();
        $feeIds = collect($items)->pluck('fee_id')->map(fn ($id) => (int) $id)->all();

        /** @var Collection<int, Fee> $fees */
        $fees = Fee::query()->whereIn('id', $feeIds)->lockForUpdate()->get()->keyBy('id');

        $lines = [];
        $subtotal = '0.00';

        foreach ($items as $item) {
            $fee = $fees->get((int) $item['fee_id']);

            if (! $fee) {
                throw ValidationException::withMessages([
                    'fees' => 'Одна из выбранных услуг не найдена.',
                ]);
            }

            if (! $fee->is_active) {
                throw ValidationException::withMessages([
                    'fees' => "Услуга «{$fee->name_ru}» отключена и не может быть добавлена в счёт.",
                ]);
            }

            $amount = $this->resolvePrice($fee, $item, $pricingDate);

            if (bccomp($amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages([
                    'fees' => "Для услуги «{$fee->name_ru}» не установлена положительная цена.",
                ]);
            }

            if (($item['first_last_month'] ?? false) === true) {
                $amount = bcmul($amount, '2.00', 2);
            }

            $subtotal = bcadd($subtotal, $amount, 2);
            $lines[] = [
                'fee_id' => $fee->id,
                'description' => $fee->name_ru,
                'amount' => $amount,
                'grade_group' => $item['grade_group'] ?? null,
                'payment_period' => $item['payment_period'] ?? null,
                'size' => $item['size'] ?? null,
                'item' => $item['item'] ?? null,
                'option_type' => $item['option_type'] ?? null,
                'option_value' => $item['option_value'] ?? null,
            ];
        }

        $discount = $this->discountAmount($subtotal, $discountType, $discountValue);
        $total = bcsub($subtotal, $discount, 2);
        $paid = $this->money($initialPaymentAmount ?? '0');

        if (bccomp($paid, '0.00', 2) < 0) {
            throw ValidationException::withMessages([
                'initial_payment_amount' => 'Первоначальный платёж не может быть отрицательным.',
            ]);
        }

        if (bccomp($paid, $total, 2) > 0) {
            throw ValidationException::withMessages([
                'initial_payment_amount' => 'Первоначальный платёж не может превышать сумму счёта.',
            ]);
        }

        $remaining = bcsub($total, $paid, 2);
        $status = match (true) {
            bccomp($paid, '0.00', 2) === 0 => Invoice::STATUS_UNPAID,
            bccomp($remaining, '0.00', 2) > 0 => Invoice::STATUS_PARTIAL,
            default => Invoice::STATUS_PAID,
        };

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
            'status' => $status,
            'currency' => self::CURRENCY,
            'line_items' => $lines,
        ];
    }

    /** @param array<string, mixed> $selection */
    private function resolvePrice(Fee $fee, array $selection, string $date): string
    {
        $query = FeePrice::query()
            ->where('fee_id', $fee->id)
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $date)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date));

        foreach (['grade_group', 'payment_period', 'size', 'item', 'option_type', 'option_value'] as $field) {
            if (filled($selection[$field] ?? null)) {
                $query->where($field, $selection[$field]);
            }
        }

        $price = $query->orderByDesc('start_date')->lockForUpdate()->first();

        return $this->money($price?->getRawOriginal('amount') ?? $fee->getRawOriginal('amount') ?? $fee->getRawOriginal('base_price') ?? '0');
    }

    private function discountAmount(string $subtotal, ?string $type, string|int|float|null $value): string
    {
        if ($type === null || $type === '') {
            return '0.00';
        }

        $amount = $this->money($value ?? '0');

        if (bccomp($amount, '0.00', 2) < 0) {
            throw ValidationException::withMessages(['discount_value' => 'Скидка не может быть отрицательной.']);
        }

        if ($type === 'percent') {
            if (bccomp($amount, '100.00', 2) > 0) {
                throw ValidationException::withMessages(['discount_value' => 'Процентная скидка не может превышать 100%.']);
            }

            return $this->roundMoney(bcdiv(bcmul($subtotal, $amount, 6), '100.00', 6));
        }

        if ($type !== 'fixed') {
            throw ValidationException::withMessages(['discount_type' => 'Выбран недопустимый тип скидки.']);
        }

        if (bccomp($amount, $subtotal, 2) > 0) {
            throw ValidationException::withMessages(['discount_value' => 'Фиксированная скидка не может превышать сумму услуг.']);
        }

        return $amount;
    }

    private function money(string|int|float $value): string
    {
        $value = is_float($value) ? number_format($value, 4, '.', '') : (string) $value;

        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Денежная сумма указана в неверном формате.']);
        }

        return $this->roundMoney($value);
    }

    private function roundMoney(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;
        $rounded = bcadd($absolute, '0.005', 2);

        return $negative && bccomp($rounded, '0.00', 2) !== 0
            ? '-' . $rounded
            : $rounded;
    }
}
