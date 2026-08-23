<?php

namespace App\Services\Finance;

use App\Models\FeePrice;
use App\Models\InvoiceItem;
use App\Models\ServiceCoverage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ServiceCoverageService
{
    public function record(InvoiceItem $item, array $data, User $actor, ?Student $expectedStudent = null): ServiceCoverage
    {
        $item->loadMissing(['invoice', 'fee', 'subscription.enrollment']);
        if ($expectedStudent && $item->invoice->student_id !== $expectedStudent->id) {
            throw ValidationException::withMessages(['invoice_item_id' => 'Позиция счёта принадлежит другому ученику.']);
        }
        if (! $item->fee_id || $item->fee?->id !== $item->fee_id) {
            throw ValidationException::withMessages(['invoice_item_id' => 'Услуга позиции счёта не определена.']);
        }
        $start = Carbon::parse($data['coverage_start']);
        $end = Carbon::parse($data['coverage_end']);
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['coverage_end' => 'Дата окончания покрытия не может быть раньше даты начала.']);
        }
        if (! in_array($data['billing_unit'], ['monthly', 'daily'], true)) {
            throw ValidationException::withMessages(['billing_unit' => 'Поддерживаются месячные и дневные единицы покрытия.']);
        }

        if (empty($data['fee_price_id'])) {
            throw ValidationException::withMessages(['fee_price_id' => 'Для тарифного покрытия требуется исходный тариф.']);
        }
        $price = FeePrice::findOrFail($data['fee_price_id']);
        if ($price->fee_id !== $item->fee_id) {
            throw ValidationException::withMessages(['fee_price_id' => 'Тариф не относится к услуге позиции счёта.']);
        }
        $metadata = $item->metadata ?? [];
        if (isset($metadata['fee_price_id']) && (int) $metadata['fee_price_id'] !== $price->id) {
            throw ValidationException::withMessages(['fee_price_id' => 'Тариф не совпадает со снимком тарифа позиции счёта.']);
        }
        foreach (['payment_period', 'option_type', 'option_value', 'grade_group', 'item', 'size'] as $field) {
            if (array_key_exists($field, $metadata) && (string) $metadata[$field] !== (string) ($price->{$field} ?? '')) {
                throw ValidationException::withMessages(['fee_price_id' => 'Измерения тарифа конфликтуют со снимком позиции счёта.']);
            }
        }
        if ($item->subscription_id) {
            $subscription = $item->subscription;
            if (! $subscription || $subscription->fee_id !== $item->fee_id || $subscription->enrollment?->student_id !== $item->invoice->student_id) {
                throw ValidationException::withMessages(['invoice_item_id' => 'Подписка позиции не соответствует ученику или услуге.']);
            }
        }
        $period = $metadata['payment_period'] ?? $price->payment_period;
        if (($data['billing_unit'] === 'monthly' && $period !== 'monthly') || ($data['billing_unit'] === 'daily' && $period !== 'daily')) {
            throw ValidationException::withMessages(['billing_unit' => 'Единица покрытия не соответствует периоду тарифа.']);
        }
        if ($data['billing_unit'] === 'monthly' && (! $start->isStartOfMonth() || ! $end->isLastOfMonth())) {
            throw ValidationException::withMessages(['coverage_start' => 'Месячное покрытие должно состоять из полных календарных месяцев.']);
        }
        if ($data['billing_unit'] === 'daily' && $item->fee->category !== \App\Models\Fee::CATEGORY_FOOD) {
            throw ValidationException::withMessages(['billing_unit' => 'Дневное покрытие поддерживается только для питания.']);
        }
        foreach ([['coverage_start', 'coverage_end'], ['period_start', 'period_end'], ['charged_period_start', 'charged_period_end']] as [$startKey, $endKey]) {
            if (isset($metadata[$startKey]) && $start->toDateString() !== Carbon::parse($metadata[$startKey])->toDateString()) {
                throw ValidationException::withMessages(['coverage_start' => 'Начало покрытия противоречит начисленному периоду позиции.']);
            }
            if (isset($metadata[$endKey]) && $end->toDateString() !== Carbon::parse($metadata[$endKey])->toDateString()) {
                throw ValidationException::withMessages(['coverage_end' => 'Окончание покрытия противоречит начисленному периоду позиции.']);
            }
        }

        return ServiceCoverage::firstOrCreate(
            ['invoice_item_id' => $item->id],
            [
                'student_id' => $item->invoice->student_id,
                'fee_id' => $item->fee_id,
                'subscription_id' => $item->subscription_id,
                'fee_price_id' => $price->id,
                'coverage_start' => $start->toDateString(),
                'coverage_end' => $end->toDateString(),
                'billing_unit' => $data['billing_unit'],
                'payment_period' => $period,
                'option_type' => $metadata['option_type'] ?? $price->option_type,
                'option_value' => $metadata['option_value'] ?? $price->option_value,
                'grade_group' => $metadata['grade_group'] ?? $price->grade_group,
                'item' => $metadata['item'] ?? $price->item,
                'size' => $metadata['size'] ?? $price->size,
                'original_unit_price' => $item->unit_price,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $actor->id,
            ],
        );
    }
}
