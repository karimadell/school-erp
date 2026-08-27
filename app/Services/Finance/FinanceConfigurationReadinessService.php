<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\PaymentPlan;
use Illuminate\Support\Collection;

/**
 * Answers "is this financial service actually chargeable right now" for a
 * given academic year — the one reusable place that decision is made, so
 * Quick Registration's UI gating (and any future readiness surface: an
 * admin dashboard, a report) can never drift from each other or from
 * InvoiceCalculationService's own resolution rules. Reads FeePrice::sellable()
 * directly (the exact scope the resolver composes), never a parallel query.
 *
 * This is deliberately data, not policy: it reports the truth for every
 * category, including tuition/registration. Whether a caller *acts* on a
 * given category's readiness (e.g. disabling a checkbox) is that caller's
 * decision — see the Quick Registration blade, which only actively gates
 * transport/food/uniform/installments today.
 */
class FinanceConfigurationReadinessService
{
    private const TUITION_CATEGORIES = [
        Fee::CATEGORY_TUITION,
        Fee::CATEGORY_TUITION_REGULAR,
        Fee::CATEGORY_TUITION_FAMILY,
        Fee::CATEGORY_TUITION_EXTERNAL,
    ];

    /**
     * Per-fee readiness for a set of fees in one academic year.
     *
     * @param  Collection<int, Fee>  $fees
     * @return Collection<int, array{ready: bool, reason: ?string}> keyed by fee id
     */
    public function forFees(Collection $fees, AcademicYear $year): Collection
    {
        if ($fees->isEmpty()) {
            return collect();
        }

        $pricesByFee = FeePrice::query()
            ->whereIn('fee_id', $fees->pluck('id'))
            ->sellable()
            ->where('academic_year_id', $year->id)
            ->get()
            ->groupBy('fee_id');

        return $fees->mapWithKeys(fn (Fee $fee) => [
            $fee->id => $this->assess($fee, $pricesByFee->get($fee->id, collect())),
        ]);
    }

    /** @return array{ready: bool, reason: ?string} */
    public function forFee(Fee $fee, AcademicYear $year): array
    {
        $prices = FeePrice::query()->where('fee_id', $fee->id)->sellable()->where('academic_year_id', $year->id)->get();

        return $this->assess($fee, $prices);
    }

    /**
     * Category-level rollup: ready when at least one active fee in that
     * category is itself ready. Covers every category the audit asked for:
     * tuition, registration, transport, food, uniform, installments.
     *
     * @return array<string, array{ready: bool, reason: ?string}>
     */
    public function forAcademicYear(AcademicYear $year): array
    {
        $fees = Fee::active()->get();
        $perFee = $this->forFees($fees, $year);

        $categoryGroups = [
            'tuition' => self::TUITION_CATEGORIES,
            'registration' => [Fee::CATEGORY_REGISTRATION],
            'transport' => [Fee::CATEGORY_TRANSPORT],
            'food' => [Fee::CATEGORY_FOOD],
            'uniform' => [Fee::CATEGORY_UNIFORM],
        ];

        $result = [];
        foreach ($categoryGroups as $key => $categories) {
            $categoryFees = $fees->whereIn('category', $categories);
            $ready = $categoryFees->isNotEmpty()
                && $categoryFees->contains(fn (Fee $fee) => $perFee->get($fee->id)['ready'] ?? false);
            $result[$key] = ['ready' => $ready, 'reason' => $ready ? null : $this->categoryReason($key)];
        }

        $result['installments'] = $this->installments();

        return $result;
    }

    /** @return array{ready: bool, reason: ?string} */
    public function installments(): array
    {
        $ready = PaymentPlan::active()->exists();

        return ['ready' => $ready, 'reason' => $ready ? null : 'Нет активных планов рассрочки.'];
    }

    /** @return array{ready: bool, reason: ?string} */
    private function assess(Fee $fee, Collection $prices): array
    {
        $ready = match ($fee->category) {
            Fee::CATEGORY_TRANSPORT => $prices->where('option_type', 'zone')->isNotEmpty(),
            Fee::CATEGORY_FOOD => $prices->where('option_type', 'meal_plan')->isNotEmpty(),
            Fee::CATEGORY_UNIFORM => $prices->whereNotNull('item')->whereNotNull('size')->isNotEmpty(),
            default => $prices->isNotEmpty(),
        };

        return ['ready' => $ready, 'reason' => $ready ? null : $this->feeReason($fee->category)];
    }

    private function feeReason(string $category): string
    {
        return match ($category) {
            Fee::CATEGORY_TRANSPORT => 'Нет тарифа ни для одной транспортной зоны на выбранный учебный год.',
            Fee::CATEGORY_FOOD => 'Нет активного плана питания с настроенной ценой.',
            Fee::CATEGORY_UNIFORM => 'Нет доступных тарифов на школьную форму.',
            default => 'Нет действующего тарифа на выбранный учебный год.',
        };
    }

    private function categoryReason(string $key): string
    {
        return match ($key) {
            'transport' => 'Нет тарифа ни для одной транспортной зоны на выбранный учебный год.',
            'food' => 'Нет активного плана питания с настроенной ценой.',
            'uniform' => 'Нет доступных тарифов на школьную форму.',
            'tuition' => 'Нет тарифа на обучение для выбранного учебного года.',
            'registration' => 'Нет тарифа на регистрационный взнос для выбранного учебного года.',
            default => 'Нет действующего тарифа на выбранный учебный год.',
        };
    }
}
