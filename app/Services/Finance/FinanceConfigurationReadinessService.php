<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\MealPlan;
use App\Models\PaymentPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Answers "is this financial service actually chargeable right now" for a
 * given academic year — the one reusable place that decision is made, so
 * Quick Registration's UI gating (and any future readiness surface: an
 * admin dashboard, a report) can never drift from each other or from
 * InvoiceCalculationService's own resolution rules.
 *
 * Phase 4A.2: academic_year_id is the primary ownership boundary for a
 * tariff, not the calendar date — so readiness is computed via
 * InvoiceCalculationService::resolvableCandidates(), the exact same
 * "would the resolver actually use this row" rule the resolver itself
 * applies (a sole same-year candidate is ready even before its own
 * start_date; several same-year candidates are disambiguated by date).
 * This never re-derives that rule as a parallel query.
 *
 * This is deliberately data, not policy: it reports the truth for every
 * category, including tuition/registration. Whether a caller *acts* on a
 * given category's readiness (e.g. disabling a checkbox) is that caller's
 * decision — see the Quick Registration blade, which only actively gates
 * transport/food/uniform/installments today.
 *
 * Phase 4A.3: readiness answers "can the employee actually select and
 * submit this service from Quick Registration?" — not merely "does a
 * FeePrice row exist". A resolvable tariff alone is not enough for Food or
 * Uniform: InvoiceCalculationService::resolvableCandidates() is
 * deliberately catalog-agnostic (it never depends on MealPlan or
 * uniform_products — see the Phase 2 architecture constraints), so a
 * legacy Food tariff whose option_value is a textual meal name instead of
 * a numeric MealPlan id is still "resolvable" by pure pricing rules, yet
 * has nothing a dropdown could ever offer. Food additionally requires the
 * resolved option_value to be a numeric id matching an ACTIVE MealPlan;
 * Uniform additionally requires an ACTIVE uniform_products row for the
 * same item+size; Transport additionally requires at least one
 * transport_routes row, because StoreQuickStudentRegistrationRequest
 * makes transport_route_id required whenever a Transport service is
 * selected — pricing alone cannot be submitted without it.
 */
class FinanceConfigurationReadinessService
{
    public function __construct(
        private InvoiceCalculationService $calculator,
    ) {
    }

    private const TUITION_CATEGORIES = [
        Fee::CATEGORY_TUITION,
        Fee::CATEGORY_TUITION_REGULAR,
        Fee::CATEGORY_TUITION_FAMILY,
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

        $today = now()->toDateString();
        $pricesByFee = FeePrice::query()
            ->whereIn('fee_id', $fees->pluck('id'))
            ->active()->where('currency', 'EGP')
            ->where('academic_year_id', $year->id)
            ->get()
            ->groupBy('fee_id');
        $activeMealPlanIds = $this->activeMealPlanIds();
        $activeUniformCombinations = $this->activeUniformCombinations();
        $hasUsableRoute = $this->hasUsableTransportRoute();

        return $fees->mapWithKeys(fn (Fee $fee) => [
            $fee->id => $this->assess(
                $fee,
                $this->calculator->resolvableCandidates($pricesByFee->get($fee->id, collect()), $today),
                $activeMealPlanIds,
                $activeUniformCombinations,
                $hasUsableRoute,
                $year->academicCalendar()->exists(),
            ),
        ]);
    }

    /** @return array{ready: bool, reason: ?string} */
    public function forFee(Fee $fee, AcademicYear $year): array
    {
        $prices = FeePrice::query()->where('fee_id', $fee->id)->active()->where('currency', 'EGP')->where('academic_year_id', $year->id)->get();
        $resolvable = $this->calculator->resolvableCandidates($prices, now()->toDateString());

        return $this->assess($fee, $resolvable, $this->activeMealPlanIds(), $this->activeUniformCombinations(), $this->hasUsableTransportRoute(), $year->academicCalendar()->exists());
    }

    private function activeMealPlanIds(): Collection
    {
        return MealPlan::query()->where('is_active', true)->pluck('id');
    }

    /** @return Collection<int, string> "item|size" pairs a Quick Registration dropdown could actually offer. */
    private function activeUniformCombinations(): Collection
    {
        return DB::table('uniform_products')->where('is_active', true)->get()
            ->map(fn ($product) => $product->name_ru.'|'.$product->size)
            ->unique();
    }

    /**
     * transport_routes has no is_active column (it is unmanaged metadata,
     * not a scoped catalog — see the Phase 1 pricing audit) — any row is
     * usable.
     */
    private function hasUsableTransportRoute(): bool
    {
        return DB::table('transport_routes')->exists();
    }

    /**
     * Category-level rollup: ready when at least one active fee in that
     * category is itself ready. Covers every category the audit asked for:
     * tuition, tuition_external, registration, transport, food, uniform,
     * installments.
     *
     * Phase 4A.3: tuition_external (Externat) is its own category, never
     * folded into ordinary tuition — an Externat-only tariff must not make
     * "tuition" report ready, and vice versa.
     *
     * @return array<string, array{ready: bool, reason: ?string}>
     */
    public function forAcademicYear(AcademicYear $year): array
    {
        $fees = Fee::active()->get();
        $perFee = $this->forFees($fees, $year);

        $categoryGroups = [
            'tuition' => self::TUITION_CATEGORIES,
            'tuition_external' => [Fee::CATEGORY_TUITION_EXTERNAL],
            'registration' => [Fee::CATEGORY_REGISTRATION],
            'transport' => [Fee::CATEGORY_TRANSPORT],
            'food' => [Fee::CATEGORY_FOOD],
            'uniform' => [Fee::CATEGORY_UNIFORM],
        ];

        $result = [];
        foreach ($categoryGroups as $key => $categories) {
            $categoryFees = $fees->whereIn('category', $categories);
            $statuses = $categoryFees->map(fn (Fee $fee) => $perFee->get($fee->id));
            $ready = $statuses->contains(fn (?array $status) => $status['ready'] ?? false);
            // Prefer a configured fee's own specific reason (e.g. "pricing
            // ready, route missing") over the generic "nothing configured"
            // fallback, which only applies when the category has no fees at all.
            $reason = $ready ? null : ($statuses->first(fn (?array $status) => filled($status['reason'] ?? null))['reason'] ?? $this->categoryReason($key));
            $result[$key] = ['ready' => $ready, 'reason' => $reason];
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
    private function assess(
        Fee $fee,
        Collection $prices,
        Collection $activeMealPlanIds,
        Collection $activeUniformCombinations,
        bool $hasUsableRoute,
        bool $hasAcademicCalendar,
    ): array {
        return match ($fee->category) {
            Fee::CATEGORY_TRANSPORT => $this->assessTransport($prices, $hasUsableRoute),
            Fee::CATEGORY_FOOD => $this->assessFood($prices, $activeMealPlanIds, $hasAcademicCalendar),
            Fee::CATEGORY_UNIFORM => $this->assessUniform($prices, $activeUniformCombinations),
            default => $this->assessDefault($prices),
        };
    }

    /** @return array{ready: bool, reason: ?string} */
    private function assessDefault(Collection $prices): array
    {
        $ready = $prices->isNotEmpty();

        return ['ready' => $ready, 'reason' => $ready ? null : 'Нет действующего тарифа на выбранный учебный год.'];
    }

    /**
     * Pricing readiness alone is not enough: StoreQuickStudentRegistrationRequest
     * makes transport_route_id required whenever a Transport service is
     * selected, so an employee cannot submit one without at least one
     * transport_routes row, even though route is unrelated to pricing.
     *
     * @return array{ready: bool, reason: ?string}
     */
    private function assessTransport(Collection $prices, bool $hasUsableRoute): array
    {
        $hasZonePricing = $prices->where('option_type', 'zone')->isNotEmpty();
        if (! $hasZonePricing) {
            return ['ready' => false, 'reason' => 'Нет тарифа ни для одной транспортной зоны на выбранный учебный год.'];
        }
        if (! $hasUsableRoute) {
            return ['ready' => false, 'reason' => 'PRICING READY / ROUTE METADATA MISSING — тарифы на зоны настроены, но нет доступных маршрутов для выбора (обязательное поле при оформлении).'];
        }

        return ['ready' => true, 'reason' => null];
    }

    /**
     * A resolvable Food tariff is not enough on its own — its option_value
     * must be a numeric id that resolves to an ACTIVE MealPlan, or Quick
     * Registration's meal-plan dropdown has nothing to offer. This is
     * deliberately not delegated to InvoiceCalculationService, which never
     * knows about the MealPlan model (see the Phase 2 architecture
     * constraints) — a legacy textual option_value is still "resolvable"
     * by pure pricing rules, but unusable here.
     *
     * @return array{ready: bool, reason: ?string}
     */
    private function assessFood(Collection $prices, Collection $activeMealPlanIds, bool $hasAcademicCalendar): array
    {
        $ready = $hasAcademicCalendar && $prices->where('payment_period', Fee::PERIOD_DAILY)->where('option_type', 'meal_plan')->contains(
            fn (FeePrice $price) => is_numeric($price->option_value) && $activeMealPlanIds->contains((int) $price->option_value)
        );

        return ['ready' => $ready, 'reason' => $ready ? null : ($hasAcademicCalendar
            ? 'Нет активного плана питания с дневным тарифом.'
            : 'Для питания не настроен учебный календарь.')];
    }

    /**
     * A resolvable Uniform tariff is not enough on its own — its item+size
     * must match an ACTIVE uniform_products row, or Quick Registration's
     * product dropdown has nothing to offer for that tariff.
     *
     * @return array{ready: bool, reason: ?string}
     */
    private function assessUniform(Collection $prices, Collection $activeUniformCombinations): array
    {
        $ready = $prices->whereNotNull('item')->whereNotNull('size')->contains(
            fn (FeePrice $price) => $activeUniformCombinations->contains($price->item.'|'.$price->size)
        );

        return ['ready' => $ready, 'reason' => $ready ? null : 'Нет доступных тарифов на школьную форму.'];
    }

    private function categoryReason(string $key): string
    {
        return match ($key) {
            'transport' => 'Нет тарифа ни для одной транспортной зоны на выбранный учебный год.',
            'food' => 'Нет активного плана питания с настроенной ценой.',
            'uniform' => 'Нет доступных тарифов на школьную форму.',
            'tuition' => 'Нет тарифа на обучение для выбранного учебного года.',
            'tuition_external' => 'Нет тарифа на экстернат для выбранного учебного года.',
            'registration' => 'Нет тарифа на регистрационный взнос для выбранного учебного года.',
            default => 'Нет действующего тарифа на выбранный учебный год.',
        };
    }
}
