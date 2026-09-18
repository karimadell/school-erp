<?php

namespace App\Console\Commands;

use App\Models\Fee;
use App\Models\FeePrice;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tuition Transition Inventory — read-only, no writes of any kind.
 *
 * Built ahead of a future unified-Tuition transition (one operational
 * Tuition Fee priced per EnrollmentMode via FeePrice.option_type=
 * enrollment_mode / option_value=EnrollmentMode.code, replacing the
 * separate tuition/tuition_regular/tuition_family/tuition_external Fee
 * categories) so any environment — local, UAT, or production — can be
 * inspected first, before any master-data or pricing change is made there.
 *
 * This command never selects a canonical operational Tuition Fee
 * automatically. It reports facts and descriptive diagnostics only; every
 * decision about which Fee is "the real one," which rows to deactivate,
 * and what prices to configure remains a separate, later, owner-approved
 * step.
 */
class TuitionTransitionInventory extends Command
{
    protected $signature = 'tuition-transition:inventory';

    protected $description = 'Read-only tuition Fee/FeePrice inventory and diagnostics ahead of the unified-Tuition transition. No writes of any kind.';

    private const RELEVANT_CATEGORIES = [
        Fee::CATEGORY_TUITION,
        Fee::CATEGORY_TUITION_REGULAR,
        Fee::CATEGORY_TUITION_FAMILY,
        Fee::CATEGORY_TUITION_EXTERNAL,
    ];

    /**
     * Copied verbatim from InvoiceCalculationService::MODE_OPTION_TYPES
     * (app/Services/Finance/InvoiceCalculationService.php) — that constant
     * is private to its class, so it cannot be referenced directly. This
     * list must be kept in sync with that constant by hand; it is the
     * exact set of option_type values production pricing treats as
     * "this FeePrice is scoped to an EnrollmentMode."
     *
     * @var array<int, string>
     */
    private const MODE_OPTION_TYPES = ['enrollment_mode', 'Форма', 'Форма обучения'];

    /**
     * The four canonical EnrollmentMode codes (Phase 2 master data).
     * option_value is compared against these literal codes only — a
     * FeePrice.option_value that happens to equal an EnrollmentMode's
     * name_ru/short_name_ru instead of its code is deliberately NOT
     * reported as a canonical-code match; code is the only identity this
     * command recognizes.
     *
     * @var array<int, string>
     */
    private const CANONICAL_MODE_CODES = ['full_time', 'family', 'external', 'no_enrollment'];

    /**
     * Tables that record actual financial/operational usage of a Fee, as
     * opposed to Fee *configuration* (fee_prices, fee_billing_periods).
     * Used only to describe a Fee as having "zero operational references"
     * in the diagnostics section — never to decide anything.
     *
     * @var array<int, string>
     */
    private const OPERATIONAL_REFERENCE_TABLES = [
        'invoice_items', 'invoices', 'invoice_fee',
        'student_service_subscriptions', 'service_coverages', 'tariff_adjustments',
    ];

    public function handle(): int
    {
        $this->components->info('Tuition Transition Inventory (read-only, no writes performed)');

        $fees = Fee::query()
            ->whereIn('category', self::RELEVANT_CATEGORIES)
            ->orderBy('category')->orderBy('id')
            ->get();

        $this->reportFees($fees);
        $prices = $this->reportFeePrices($fees);
        $referenceCounts = $this->reportReferenceCounts($fees);
        $this->reportDiagnostics($fees, $prices, $referenceCounts);

        $this->newLine();
        $this->components->info('Inventory complete. No data was created, updated, or deleted.');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Fee>  $fees
     */
    private function reportFees(Collection $fees): void
    {
        $this->header('Relevant Fee rows (tuition, tuition_regular, tuition_family, tuition_external)');

        if ($fees->isEmpty()) {
            $this->warn('No relevant Fee rows exist.');

            return;
        }

        $this->table(
            ['id', 'category', 'name_ru', 'is_active', 'is_test_data'],
            $fees->map(fn (Fee $fee) => [
                $fee->id,
                $fee->category,
                $fee->name_ru,
                $fee->is_active ? 'yes' : 'no',
                $fee->is_test_data ? 'yes' : 'no',
            ]),
        );
    }

    /**
     * @param  Collection<int, Fee>  $fees
     * @return Collection<int, FeePrice>
     */
    private function reportFeePrices(Collection $fees): Collection
    {
        $this->header('FeePrice rows for the relevant Fees');

        if ($fees->isEmpty()) {
            $this->warn('No relevant Fee rows exist, so no FeePrice rows to report.');

            return new Collection;
        }

        $prices = FeePrice::query()
            ->whereIn('fee_id', $fees->pluck('id'))
            ->with(['fee:id,category,name_ru', 'academicYear:id,name', 'grade:id,name'])
            ->orderBy('fee_id')
            ->orderBy('academic_year_id')
            ->orderBy('grade_id')
            ->orderBy('grade_group')
            ->orderBy('payment_period')
            ->orderBy('option_type')
            ->orderBy('option_value')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        if ($prices->isEmpty()) {
            $this->warn('No FeePrice rows exist for the relevant Fees.');

            return $prices;
        }

        $this->table(
            [
                'id', 'fee_id', 'fee category', 'fee name_ru', 'academic_year_id', 'academic year',
                'grade_id', 'grade', 'grade_group', 'payment_period', 'option_type', 'option_value',
                'amount', 'start_date', 'end_date', 'is_active',
            ],
            $prices->map(fn (FeePrice $price) => [
                $price->id,
                $price->fee_id,
                $price->fee?->category ?? '(NULL)',
                $price->fee?->name_ru ?? '(NULL)',
                $price->academic_year_id ?? '(NULL)',
                $price->academicYear?->name ?? '(нет данных)',
                $price->grade_id ?? '(NULL)',
                $price->grade?->name ?? '(нет данных)',
                $price->grade_group ?? '(NULL)',
                $price->payment_period ?? '(NULL)',
                $price->option_type ?? '(NULL)',
                $price->option_value ?? '(NULL)',
                $price->amount,
                optional($price->start_date)->toDateString() ?? '(NULL)',
                optional($price->end_date)->toDateString() ?? '(бессрочно)',
                $price->is_active ? 'yes' : 'no',
            ]),
        );

        return $prices;
    }

    /**
     * One grouped-count query per reference table (never one query per
     * Fee), so this command's query count stays constant regardless of how
     * many relevant Fee rows exist.
     *
     * @param  Collection<int, Fee>  $fees
     * @return array<string, array<int, int>> keyed by table name, then fee_id
     */
    private function reportReferenceCounts(Collection $fees): array
    {
        $this->header('Reference counts per Fee (aggregate counts only)');

        if ($fees->isEmpty()) {
            $this->warn('No relevant Fee rows exist, so no reference counts to report.');

            return [];
        }

        $feeIds = $fees->pluck('id')->all();

        // fee_billing_periods is the real table/model (FeeBillingPeriod) —
        // the codebase has no fee_billing_options table; that name does
        // not exist in this schema.
        $tables = [
            'invoice_items', 'invoices', 'invoice_fee', 'fee_prices',
            'student_service_subscriptions', 'service_coverages', 'tariff_adjustments', 'fee_billing_periods',
        ];

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)
                ->whereIn('fee_id', $feeIds)
                ->selectRaw('fee_id, COUNT(*) as total')
                ->groupBy('fee_id')
                ->pluck('total', 'fee_id')
                ->all();
        }

        $this->table(
            [
                'id', 'category', 'name_ru', 'invoice_items', 'invoices', 'invoice_fee',
                'fee_prices', 'subscriptions', 'coverages', 'tariff_adjustments', 'billing_periods',
            ],
            $fees->map(fn (Fee $fee) => [
                $fee->id,
                $fee->category,
                $fee->name_ru,
                $counts['invoice_items'][$fee->id] ?? 0,
                $counts['invoices'][$fee->id] ?? 0,
                $counts['invoice_fee'][$fee->id] ?? 0,
                $counts['fee_prices'][$fee->id] ?? 0,
                $counts['student_service_subscriptions'][$fee->id] ?? 0,
                $counts['service_coverages'][$fee->id] ?? 0,
                $counts['tariff_adjustments'][$fee->id] ?? 0,
                $counts['fee_billing_periods'][$fee->id] ?? 0,
            ]),
        );

        return $counts;
    }

    /**
     * @param  Collection<int, Fee>  $fees
     * @param  Collection<int, FeePrice>  $prices
     * @param  array<string, array<int, int>>  $referenceCounts
     */
    private function reportDiagnostics(Collection $fees, Collection $prices, array $referenceCounts): void
    {
        $this->header('Diagnostics (descriptive only — no automatic canonical-Fee selection, no repair)');

        if ($fees->isEmpty()) {
            $this->info('No relevant Fee rows exist — nothing to diagnose.');

            return;
        }

        $lines = [];

        // A: count by category.
        foreach ($fees->groupBy('category') as $category => $group) {
            $lines[] = "CATEGORY COUNT — \"{$category}\": {$group->count()} row(s).";
        }

        // B: multiple rows in the same category.
        foreach ($fees->groupBy('category')->filter(fn ($g) => $g->count() > 1) as $category => $group) {
            $ids = $group->pluck('id')->implode(', ');
            $lines[] = "MULTIPLE ROWS SAME CATEGORY — \"{$category}\" has {$group->count()} rows (ids: {$ids}) — no row is automatically preferred.";
        }

        // C: active vs inactive.
        $lines[] = 'ACTIVE — '.$fees->where('is_active', true)->count().' row(s); INACTIVE — '.$fees->where('is_active', false)->count().' row(s).';

        // D: is_test_data.
        foreach ($fees->where('is_test_data', true) as $fee) {
            $lines[] = "TEST DATA — Fee id={$fee->id} category=\"{$fee->category}\" is flagged is_test_data.";
        }

        // E: per-Fee zero-FeePrice / zero-operational-reference / specific-reference flags.
        foreach ($fees as $fee) {
            $priceCount = $referenceCounts['fee_prices'][$fee->id] ?? 0;
            if ($priceCount === 0) {
                $lines[] = "ZERO FEEPRICE ROWS — Fee id={$fee->id} category=\"{$fee->category}\" has no FeePrice rows at all.";
            }

            $operationalTotal = collect(self::OPERATIONAL_REFERENCE_TABLES)
                ->sum(fn ($table) => $referenceCounts[$table][$fee->id] ?? 0);
            if ($operationalTotal === 0) {
                $lines[] = "ZERO OPERATIONAL REFERENCES — Fee id={$fee->id} category=\"{$fee->category}\" has no invoice_items/invoices/invoice_fee/subscription/coverage/adjustment references.";
            }

            foreach (['invoice_items' => 'InvoiceItem', 'service_coverages' => 'ServiceCoverage', 'student_service_subscriptions' => 'StudentServiceSubscription', 'tariff_adjustments' => 'TariffAdjustment'] as $table => $label) {
                $count = $referenceCounts[$table][$fee->id] ?? 0;
                if ($count > 0) {
                    $lines[] = "HAS {$label} REFERENCES — Fee id={$fee->id} category=\"{$fee->category}\" has {$count} {$label} reference(s).";
                }
            }
        }

        // F/G: legacy category rollcall.
        $externalFees = $fees->where('category', Fee::CATEGORY_TUITION_EXTERNAL);
        $lines[] = $externalFees->isEmpty()
            ? 'LEGACY EXTERNAL — no Fee rows found in category "'.Fee::CATEGORY_TUITION_EXTERNAL.'".'
            : 'LEGACY EXTERNAL — '.$externalFees->count().' row(s) in category "'.Fee::CATEGORY_TUITION_EXTERNAL.'" (ids: '.$externalFees->pluck('id')->implode(', ').').';

        $familyFees = $fees->where('category', Fee::CATEGORY_TUITION_FAMILY);
        $lines[] = $familyFees->isEmpty()
            ? 'LEGACY FAMILY — no Fee rows found in category "'.Fee::CATEGORY_TUITION_FAMILY.'".'
            : 'LEGACY FAMILY — '.$familyFees->count().' row(s) in category "'.Fee::CATEGORY_TUITION_FAMILY.'" (ids: '.$familyFees->pluck('id')->implode(', ').').';

        // H: mode-scoped FeePrice rows and canonical-code matching (diagnostic only).
        $modeScoped = $prices->filter(fn (FeePrice $p) => in_array($p->option_type, self::MODE_OPTION_TYPES, true));
        if ($modeScoped->isEmpty()) {
            $lines[] = 'MODE-SCOPED PRICES — none found (no FeePrice row has option_type in '.implode('/', self::MODE_OPTION_TYPES).').';
        } else {
            foreach ($modeScoped as $price) {
                $matchesCanonicalCode = in_array($price->option_value, self::CANONICAL_MODE_CODES, true);
                $lines[] = sprintf(
                    'MODE-SCOPED PRICE — FeePrice id=%d fee_id=%d option_type="%s" option_value="%s" — %s',
                    $price->id, $price->fee_id, $price->option_type, $price->option_value,
                    $matchesCanonicalCode
                        ? 'matches a canonical EnrollmentMode code exactly.'
                        : 'does NOT match any canonical EnrollmentMode code by exact string equality (name_ru/short_name_ru matches, if any, are not treated as identity).'
                );
            }
        }

        // I/J: dimensional overlap and generic/mode-scoped coexistence,
        // grouped by the same literal dimensions dimensionalCandidates()
        // scopes on (fee_id, academic_year_id, grade_id, grade_group,
        // payment_period, size, item) before it branches on option_type/
        // option_value. This is a literal, exact-value grouping — it does
        // NOT reproduce dimensionalCandidates()'s grade_id<->grade_group
        // fallback inference (a grade_id belonging to a grade_group could
        // still collide with a grade_group-scoped row at resolution time;
        // detecting that would require duplicating that private inference
        // logic, which this command does not attempt). Anything reported
        // here is a potential overlap for a human to review with real
        // Enrollment/date context — never a declared, certain conflict.
        $activeScoped = $prices->where('is_active', true)
            ->groupBy(fn (FeePrice $p) => implode('|', [
                $p->fee_id, $p->academic_year_id, $p->grade_id, $p->grade_group, $p->payment_period, $p->size, $p->item,
            ]));

        foreach ($activeScoped as $scopeKey => $group) {
            $byOptionType = $group->groupBy('option_type');
            $hasGeneric = $byOptionType->has(null);
            $hasModeScoped = $byOptionType->keys()->intersect(self::MODE_OPTION_TYPES)->isNotEmpty();

            if ($hasGeneric && $hasModeScoped) {
                $lines[] = "GENERIC + MODE-SCOPED COEXIST — scope [{$scopeKey}] has both a generic (option_type NULL) and a mode-scoped FeePrice active simultaneously; production pricing resolution will prefer the mode-scoped set exclusively for this scope (see InvoiceCalculationService::dimensionalCandidates()), making the generic row unreachable there.";
            }

            foreach ($group->groupBy(fn (FeePrice $p) => ($p->option_type ?? '').'::'.($p->option_value ?? '')) as $comboKey => $comboGroup) {
                if ($comboGroup->count() > 1) {
                    $ids = $comboGroup->pluck('id')->implode(', ');
                    $lines[] = "POTENTIAL OVERLAP — scope [{$scopeKey}] option[{$comboKey}] has {$comboGroup->count()} active FeePrice rows (ids: {$ids}) sharing identical dimensions; a specific pricing date would disambiguate by date window (see selectAmongCandidates()), or fail loudly if the windows genuinely overlap — reported for human review, not a declared conflict.";
                }
            }
        }

        foreach ($lines as $line) {
            $this->line('- '.$line);
        }
    }

    private function header(string $title): void
    {
        $this->newLine();
        $this->components->twoColumnDetail("<fg=yellow;options=bold>{$title}</>", '');
    }
}
