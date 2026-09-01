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

        $price = $this->sourceTariff($item);
        if (empty($data['fee_price_id']) || (int) $data['fee_price_id'] !== $price->id) {
            throw ValidationException::withMessages(['fee_price_id' => 'Выбранный тариф не совпадает с исходным тарифом позиции счёта.']);
        }
        $metadata = $item->metadata ?? [];
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

    /**
     * Finance V2, Phase 2D corrective pass (P0 Blocker 2 / yearly & Food
     * adjustment basis). record()/sourceTariff() above hard-require the
     * coverage's tariff to be exactly the price the item was actually
     * charged at (payment_period AND unit_price must match) — correct for
     * the original manual-form use case, but structurally incompatible
     * with a Fee billed quarterly/yearly or Food charged at a non-daily
     * rate, whose coverage/tariff-adjustment BASIS must legitimately be a
     * DIFFERENT (monthly or daily) tariff than the one actually charged —
     * see InvoiceCalculationService::resolveCoverageBasisPrice(). This is
     * a narrower, separate entry point for exactly that case — it does
     * NOT touch record()/sourceTariff(), which remain unchanged and still
     * govern the existing manual-form path exactly as before, still fully
     * validated, still requiring an exact charged-price match there.
     *
     * Reuses every structural validation record() applies EXCEPT the
     * charged-price-must-match check: item/fee validity, coverage date
     * ordering, billing_unit validity, subscription consistency, the
     * full-calendar-month rule for monthly, and food-only for daily.
     * $basisPrice itself is validated directly (fee, currency, academic
     * year, and payment_period must equal $data['billing_unit']) instead
     * of being sourced from the item's own charge.
     */
    public function recordWithBasisPrice(InvoiceItem $item, FeePrice $basisPrice, array $data, User $actor): ServiceCoverage
    {
        $item->loadMissing(['invoice', 'fee', 'subscription.enrollment']);
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
        if ($basisPrice->fee_id !== $item->fee_id) {
            throw ValidationException::withMessages(['fee_price_id' => 'Базовый тариф не относится к услуге позиции счёта.']);
        }
        if ($basisPrice->currency !== 'EGP' || ! $basisPrice->is_active) {
            throw ValidationException::withMessages(['fee_price_id' => 'Базовый тариф недействителен.']);
        }
        if ($item->invoice?->academic_year_id && $basisPrice->academic_year_id !== $item->invoice->academic_year_id) {
            throw ValidationException::withMessages(['fee_price_id' => 'Базовый тариф относится к другому учебному году.']);
        }
        if ($basisPrice->payment_period !== $data['billing_unit']) {
            throw ValidationException::withMessages(['billing_unit' => 'Базовый тариф не соответствует единице покрытия.']);
        }
        $metadata = $item->metadata ?? [];
        if ($item->subscription_id) {
            $subscription = $item->subscription;
            if (! $subscription || $subscription->fee_id !== $item->fee_id || $subscription->enrollment?->student_id !== $item->invoice->student_id) {
                throw ValidationException::withMessages(['invoice_item_id' => 'Подписка позиции не соответствует ученику или услуге.']);
            }
        }
        if ($data['billing_unit'] === 'monthly' && (! $start->isStartOfMonth() || ! $end->isLastOfMonth())) {
            throw ValidationException::withMessages(['coverage_start' => 'Месячное покрытие должно состоять из полных календарных месяцев.']);
        }
        if ($data['billing_unit'] === 'daily' && $item->fee->category !== \App\Models\Fee::CATEGORY_FOOD) {
            throw ValidationException::withMessages(['billing_unit' => 'Дневное покрытие поддерживается только для питания.']);
        }

        return ServiceCoverage::firstOrCreate(
            ['invoice_item_id' => $item->id],
            [
                'student_id' => $item->invoice->student_id,
                'fee_id' => $item->fee_id,
                'subscription_id' => $item->subscription_id,
                'fee_price_id' => $basisPrice->id,
                'coverage_start' => $start->toDateString(),
                'coverage_end' => $end->toDateString(),
                'billing_unit' => $data['billing_unit'],
                'payment_period' => $basisPrice->payment_period,
                'option_type' => $metadata['option_type'] ?? $basisPrice->option_type,
                'option_value' => $metadata['option_value'] ?? $basisPrice->option_value,
                'grade_group' => $metadata['grade_group'] ?? $basisPrice->grade_group,
                'item' => $metadata['item'] ?? $basisPrice->item,
                'size' => $metadata['size'] ?? $basisPrice->size,
                'original_unit_price' => $basisPrice->amount,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $actor->id,
            ],
        );
    }

    public function sourceTariff(InvoiceItem $item): FeePrice
    {
        $item->loadMissing(['invoice', 'fee']);
        $metadata = $item->metadata ?? [];
        if (empty($metadata['fee_price_id']) || ! is_numeric($metadata['fee_price_id'])) {
            throw ValidationException::withMessages([
                'invoice_item_id' => 'Позиция счёта не содержит подтверждённую ссылку на исходный тариф.',
            ]);
        }

        $price = FeePrice::find((int) $metadata['fee_price_id']);
        if (! $price) {
            throw ValidationException::withMessages(['fee_price_id' => 'Исходный тариф позиции счёта больше не существует.']);
        }
        if ($price->fee_id !== $item->fee_id) {
            throw ValidationException::withMessages(['fee_price_id' => 'Тариф не относится к услуге позиции счёта.']);
        }
        if ($price->currency !== 'EGP') {
            throw ValidationException::withMessages(['fee_price_id' => 'Для покрытия поддерживаются только тарифы в EGP.']);
        }
        if ($item->invoice?->academic_year_id && $price->academic_year_id !== $item->invoice->academic_year_id) {
            throw ValidationException::withMessages(['fee_price_id' => 'Тариф относится к другому учебному году.']);
        }
        foreach (['grade_id', 'grade_group', 'payment_period', 'option_type', 'option_value', 'item', 'size'] as $field) {
            if (array_key_exists($field, $metadata) && (string) $metadata[$field] !== (string) ($price->{$field} ?? '')) {
                throw ValidationException::withMessages(['fee_price_id' => 'Измерения тарифа конфликтуют со снимком позиции счёта.']);
            }
        }
        if (! in_array($price->payment_period, ['monthly', 'daily'], true)) {
            throw ValidationException::withMessages(['fee_price_id' => 'Тариф не поддерживает месячное или дневное покрытие.']);
        }
        if ($price->payment_period === 'daily' && $item->fee?->category !== \App\Models\Fee::CATEGORY_FOOD) {
            throw ValidationException::withMessages(['fee_price_id' => 'Дневное покрытие поддерживается только для питания.']);
        }
        if (bccomp((string) $item->unit_price, (string) $price->amount, 2) !== 0) {
            throw ValidationException::withMessages(['fee_price_id' => 'Цена позиции счёта не совпадает со снимком исходного тарифа.']);
        }

        return $price;
    }
}
