<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeeBillingPeriod;
use App\Models\FeePrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AcademicYear20262027TuitionPriceDeploymentService
{
    public const YEAR_NAME = '2026 / 2027';

    public const EFFECTIVE_START = '2026-09-01';

    public const OPTION_TYPE = 'enrollment_mode';

    public const CURRENCY = 'EGP';

    public const REASON = 'Утвержденный прайс-лист обучения 2026/2027 по формам обучения';

    private const MODE_CODES = [
        EnrollmentMode::FULL_TIME,
        EnrollmentMode::FAMILY,
        EnrollmentMode::NO_ENROLLMENT,
        EnrollmentMode::EXTERNAL,
    ];

    private const STANDARD_AMOUNTS = [
        'Подготовительный класс' => ['monthly' => '4500.00', 'yearly' => '40500.00'],
        '1–4 классы' => ['monthly' => '5500.00', 'yearly' => '49500.00'],
        '5–6 классы' => ['monthly' => '6500.00', 'yearly' => '58500.00'],
        '7–8 классы' => ['monthly' => '7500.00', 'yearly' => '67500.00'],
        '9–11 классы' => ['monthly' => '9000.00', 'yearly' => '81000.00'],
    ];

    private const EXTERNAL_AMOUNTS = [
        '1–4 классы' => ['monthly' => '3200.00', 'yearly' => '25600.00'],
    ];

    private const LEGACY_MODE_OPTION_TYPES = ['Форма', 'Форма обучения'];

    /** Build the complete plan using SELECTs only. */
    public function plan(): array
    {
        return $this->buildPlan(false);
    }

    /** Re-plan, insert, and verify atomically; no existing row is ever changed. */
    public function apply(): array
    {
        $result = DB::transaction(function (): array {
            $plan = $this->buildPlan(true);
            if ($plan['conflicts'] !== []) {
                throw new RuntimeException('Tuition price deployment aborted: '.implode(' ', $plan['conflicts']));
            }

            $created = 0;
            foreach ($plan['rows'] as $row) {
                if ($row['status'] !== 'CREATE') {
                    continue;
                }

                FeePrice::create($this->persistedAttributes($row));
                $created++;
            }

            $this->beforeFinalVerification();
            $this->verifyExpectedMatrix($plan);
            $plan['created_count'] = $created;
            $plan['verified_count'] = 32;

            return $plan;
        });

        // A post-commit read confirms what the operator will actually use.
        $this->verifyExpectedMatrix($result);

        return $result;
    }

    /** Test seam for proving rollback after inserts but before verification. */
    protected function beforeFinalVerification(): void {}

    private function buildPlan(bool $lock): array
    {
        $year = $this->resolveAcademicYear($lock);
        $fee = $this->resolveTuitionFee($lock);
        $modes = $this->resolveEnrollmentModes($lock);
        $allowedPeriods = $this->resolveAllowedBillingPeriods($fee, $lock);
        $expected = $this->expectedRows($fee, $year);

        if (count($expected) !== 32 || collect($expected)->map(fn (array $row) => $this->scopeKey($row))->unique()->count() !== 32) {
            throw new RuntimeException('Internal safety assertion failed: the approved matrix must contain exactly 32 unique scopes.');
        }

        $existing = $this->relevantPrices($fee, $year, $lock);
        $modeAliases = $this->modeAliases($modes);
        $rows = [];
        $conflicts = [];

        foreach ($expected as $intended) {
            [$status, $issues] = $this->classify($intended, $existing, $modeAliases);
            $rows[] = $intended + ['status' => $status];
            array_push($conflicts, ...$issues);
        }

        $expectedKeys = collect($expected)->map(fn (array $row) => $this->scopeKey($row));
        $canonicalModeRows = $existing->filter(fn (FeePrice $price) => $price->option_type === self::OPTION_TYPE);
        foreach ($canonicalModeRows as $price) {
            if (! in_array($price->option_value, self::MODE_CODES, true)) {
                $conflicts[] = "Unknown enrollment_mode code on FeePrice #{$price->id}: {$price->option_value}.";

                continue;
            }
            $key = $this->scopeKey([
                'option_value' => $price->option_value,
                'grade_group' => $price->grade_group,
                'payment_period' => $price->payment_period,
            ]);
            if (! $expectedKeys->contains($key)) {
                $conflicts[] = "Unapproved mode-scoped Tuition tariff exists outside the 32-row matrix: {$key} (FeePrice #{$price->id}).";
            }
        }

        foreach ($existing->whereIn('option_type', self::LEGACY_MODE_OPTION_TYPES) as $price) {
            if (collect($modeAliases)->flatten()->contains((string) $price->option_value)) {
                $conflicts[] = "Legacy/alias mode tariff exists outside canonical identity (FeePrice #{$price->id}).";
            }
        }

        $totals = collect($rows)->countBy('status');

        return [
            'academic_year' => [
                'id' => $year->id,
                'name' => $year->name,
                'start_date' => $year->start_date->toDateString(),
                'end_date' => $year->end_date->toDateString(),
            ],
            'fee' => ['id' => $fee->id, 'name' => $fee->name_ru],
            'enrollment_modes' => self::MODE_CODES,
            'allowed_billing_periods' => $allowedPeriods,
            'generic_rows' => $existing->filter(fn (FeePrice $price) => $price->option_type === null && $price->option_value === null)->count(),
            'rows' => $rows,
            'totals' => [
                'CREATE' => (int) ($totals['CREATE'] ?? 0),
                'IDENTICAL' => (int) ($totals['IDENTICAL'] ?? 0),
                'CONFLICT' => (int) ($totals['CONFLICT'] ?? 0),
            ],
            'conflicts' => array_values(array_unique($conflicts)),
        ];
    }

    private function resolveAcademicYear(bool $lock): AcademicYear
    {
        $query = AcademicYear::query()->where('name', self::YEAR_NAME);
        $years = $this->get($query, $lock);
        if ($years->count() !== 1) {
            throw new RuntimeException('Expected exactly one AcademicYear named '.self::YEAR_NAME.'; found '.$years->count().'.');
        }

        $year = $years->first();
        $effective = Carbon::parse(self::EFFECTIVE_START);
        if ($effective->lt($year->start_date) || $effective->gt($year->end_date)) {
            throw new RuntimeException('Effective date 2026-09-01 is outside the resolved AcademicYear.');
        }

        return $year;
    }

    private function resolveTuitionFee(bool $lock): Fee
    {
        $fees = $this->get(Fee::query()
            ->where('category', Fee::CATEGORY_TUITION)
            ->where('is_test_data', false)
            ->where('is_active', true), $lock);

        if ($fees->count() !== 1) {
            throw new RuntimeException('Expected exactly one active, non-test unified Tuition Fee; found '.$fees->count().'.');
        }

        return $fees->first();
    }

    /** @return Collection<string, EnrollmentMode> */
    private function resolveEnrollmentModes(bool $lock): Collection
    {
        $modes = $this->get(EnrollmentMode::query()->whereIn('code', self::MODE_CODES), $lock)->groupBy('code');
        foreach (self::MODE_CODES as $code) {
            if (($modes->get($code)?->count() ?? 0) !== 1) {
                throw new RuntimeException("Expected exactly one EnrollmentMode with code {$code}.");
            }
        }

        return $modes->map->first();
    }

    /** @return array<int, string> */
    private function resolveAllowedBillingPeriods(Fee $fee, bool $lock): array
    {
        $rows = $this->get(FeeBillingPeriod::query()->where('fee_id', $fee->id), $lock);
        $periods = $rows->pluck('billing_period')->unique()->sort()->values();
        foreach ([FeeBillingPeriod::PERIOD_MONTHLY, FeeBillingPeriod::PERIOD_YEARLY] as $required) {
            if (! $periods->contains($required)) {
                throw new RuntimeException("Unified Tuition Fee does not allow required billing period {$required}.");
            }
        }

        return $periods->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function expectedRows(Fee $fee, AcademicYear $year): array
    {
        if (array_keys(self::STANDARD_AMOUNTS) !== FeePrice::GRADE_GROUPS) {
            throw new RuntimeException('Approved grade groups no longer match FeePrice::GRADE_GROUPS.');
        }

        $rows = [];
        foreach (self::MODE_CODES as $code) {
            $matrix = $code === EnrollmentMode::EXTERNAL ? self::EXTERNAL_AMOUNTS : self::STANDARD_AMOUNTS;
            foreach (FeePrice::GRADE_GROUPS as $group) {
                foreach ($matrix[$group] ?? [] as $period => $amount) {
                    $rows[] = [
                        'fee_id' => $fee->id,
                        'academic_year_id' => $year->id,
                        'grade_id' => null,
                        'grade_group' => $group,
                        'payment_period' => $period,
                        'amount' => $amount,
                        'currency' => self::CURRENCY,
                        'start_date' => self::EFFECTIVE_START,
                        'end_date' => $year->end_date->toDateString(),
                        'option_type' => self::OPTION_TYPE,
                        'option_value' => $code,
                        'item' => null,
                        'size' => null,
                        'is_active' => true,
                    ];
                }
            }
        }

        return $rows;
    }

    private function relevantPrices(Fee $fee, AcademicYear $year, bool $lock): Collection
    {
        return $this->get(FeePrice::query()
            ->where('fee_id', $fee->id)
            ->where('academic_year_id', $year->id), $lock);
    }

    /** @return array{string, array<int, string>} */
    private function classify(array $intended, Collection $existing, array $modeAliases): array
    {
        $base = $existing->filter(fn (FeePrice $price) => $price->grade_group === $intended['grade_group']
            && $price->payment_period === $intended['payment_period']
        );

        $legacy = $base->filter(fn (FeePrice $price) => in_array($price->option_type, array_merge([self::OPTION_TYPE], self::LEGACY_MODE_OPTION_TYPES), true)
            && in_array((string) $price->option_value, $modeAliases[$intended['option_value']], true)
            && ! ($price->option_type === self::OPTION_TYPE && $price->option_value === $intended['option_value'])
        );
        if ($legacy->isNotEmpty()) {
            return ['CONFLICT', ["Legacy/alias mode tariff makes scope {$this->scopeKey($intended)} ambiguous (FeePrice ids {$legacy->pluck('id')->implode(',')})."]];
        }

        $sameMode = $base->filter(fn (FeePrice $price) => $price->option_type === self::OPTION_TYPE
            && $price->option_value === $intended['option_value']
        );
        if ($sameMode->count() > 1) {
            return ['CONFLICT', ["Duplicate FeePrice rows for {$this->scopeKey($intended)} (ids {$sameMode->pluck('id')->implode(',')})."]];
        }
        if ($sameMode->isEmpty()) {
            return ['CREATE', []];
        }

        /** @var FeePrice $price */
        $price = $sameMode->first();
        $materiallyIdentical = $price->grade_id === null
            && $price->item === null
            && $price->size === null
            && $price->currency === $intended['currency']
            && bccomp((string) $price->getRawOriginal('amount'), $intended['amount'], 2) === 0
            && $price->start_date?->toDateString() === $intended['start_date']
            && $price->end_date?->toDateString() === $intended['end_date']
            && $price->is_active === true;

        if ($materiallyIdentical) {
            return ['IDENTICAL', []];
        }

        $overlaps = $price->is_active
            && $price->start_date?->lte($intended['end_date'])
            && ($price->end_date === null || $price->end_date->gte($intended['start_date']));
        $reason = $overlaps ? 'overlapping active row' : 'different material values';

        return ['CONFLICT', ["Conflicting {$reason} for {$this->scopeKey($intended)} (FeePrice id {$price->id})."]];
    }

    private function verifyExpectedMatrix(array $plan): void
    {
        $prices = FeePrice::query()
            ->where('fee_id', $plan['fee']['id'])
            ->where('academic_year_id', $plan['academic_year']['id'])
            ->where('option_type', self::OPTION_TYPE)
            ->whereIn('option_value', self::MODE_CODES)
            ->get();

        foreach ($plan['rows'] as $intended) {
            $matches = $prices->filter(fn (FeePrice $price) => $price->grade_group === $intended['grade_group']
                && $price->payment_period === $intended['payment_period']
                && $price->option_value === $intended['option_value']
            );
            if ($matches->count() !== 1) {
                throw new RuntimeException("Final verification failed for {$this->scopeKey($intended)}.");
            }
            [$status] = $this->classify($intended, $matches, [$intended['option_value'] => [$intended['option_value']]]);
            if ($status !== 'IDENTICAL') {
                throw new RuntimeException("Final verification found non-identical data for {$this->scopeKey($intended)}.");
            }
        }

        $intendedKeys = collect($plan['rows'])->map(fn (array $row) => $this->scopeKey($row));
        $actualIntended = $prices->filter(fn (FeePrice $price) => $intendedKeys->contains($this->scopeKey([
            'option_value' => $price->option_value,
            'grade_group' => $price->grade_group,
            'payment_period' => $price->payment_period,
        ])));
        if ($actualIntended->count() !== 32 || $actualIntended->contains(fn (FeePrice $price) => $price->payment_period === FeeBillingPeriod::PERIOD_QUARTERLY)) {
            throw new RuntimeException('Final verification did not find exactly the approved 32-row matrix.');
        }
    }

    private function persistedAttributes(array $row): array
    {
        return collect($row)->only([
            'fee_id', 'academic_year_id', 'grade_id', 'grade_group', 'payment_period', 'amount',
            'currency', 'start_date', 'end_date', 'option_type', 'option_value', 'item', 'size', 'is_active',
        ])->merge(['change_reason' => self::REASON])->all();
    }

    private function scopeKey(array $row): string
    {
        return implode('|', [$row['option_value'], $row['grade_group'], $row['payment_period']]);
    }

    /** @return array<string, array<int, string>> */
    private function modeAliases(Collection $modes): array
    {
        return $modes->mapWithKeys(fn (EnrollmentMode $mode, string $code) => [$code => collect([
            $mode->code, $mode->name_ru, $mode->short_name_ru,
        ])->filter()->map(fn ($value) => (string) $value)->unique()->values()->all()])->all();
    }

    private function get(Builder $query, bool $lock): Collection
    {
        return $lock ? $query->lockForUpdate()->get() : $query->get();
    }
}
