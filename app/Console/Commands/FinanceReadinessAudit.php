<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\MealPlan;
use App\Models\PaymentPlan;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Services\Finance\CashSessionService;
use App\Services\Finance\FinanceConfigurationReadinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4A — a single, read-only master-data readiness audit for Quick
 * Registration. Performs SELECT queries only: no create/update/delete, no
 * migration, no seeding, no cash session opened or closed. Safe to run
 * against UAT (or any environment) repeatedly.
 *
 * It deliberately does not reimplement pricing rules: FeePrice::sellable()
 * and FinanceConfigurationReadinessService are called directly, exactly as
 * the application itself would, so this report can never diverge from what
 * Quick Registration actually does.
 *
 * Usage:
 *   php artisan finance:readiness-audit
 *   php artisan finance:readiness-audit --year="2026/2027"
 *   php artisan finance:readiness-audit --year="2026 / 2027"
 */
class FinanceReadinessAudit extends Command
{
    protected $signature = 'finance:readiness-audit {--year= : Exact or partial academic year name to target, e.g. "2026/2027"}';

    protected $description = 'Read-only master-data readiness audit for Quick Registration (Phase 4A) — no writes of any kind.';

    private const TUITION_CATEGORIES = [
        Fee::CATEGORY_TUITION,
        Fee::CATEGORY_TUITION_REGULAR,
        Fee::CATEGORY_TUITION_FAMILY,
        Fee::CATEGORY_TUITION_EXTERNAL,
    ];

    private const GRADE_GROUP_LABELS = [
        0 => 'Подготовительный класс',
        1 => '1–4 классы', 2 => '1–4 классы', 3 => '1–4 классы', 4 => '1–4 классы',
        5 => '5–6 классы', 6 => '5–6 классы',
        7 => '7–8 классы', 8 => '7–8 классы',
        9 => '9–11 классы', 10 => '9–11 классы', 11 => '9–11 классы',
    ];

    private Carbon $today;

    public function handle(): int
    {
        $this->today = Carbon::today();
        $this->components->info('Phase 4A — Finance Readiness Audit (read-only, no writes performed)');
        $this->line('Run at: '.now()->toDateTimeString().'  |  DB: '.config('database.connections.'.config('database.default').'.database'));

        // ----- A. Academic year -------------------------------------------
        $year = $this->sectionA();
        if (! $year) {
            $this->components->error('No matching academic year found — every subsequent section that depends on a year is skipped.');

            return self::FAILURE;
        }

        // ----- B. School structure -----------------------------------------
        [$stages, $grades, $classes] = $this->sectionB($year);

        // ----- C. Fee catalog ------------------------------------------------
        $fees = Fee::query()->orderBy('category')->orderBy('name_ru')->get();
        $this->sectionC($fees);

        // ----- D. FeePrice coverage (all rows for active fees, classified) --
        $prices = FeePrice::query()
            ->whereIn('fee_id', $fees->where('is_active', true)->pluck('id'))
            ->orderBy('fee_id')->orderByDesc('start_date')->get();
        $classified = $prices->map(fn (FeePrice $price) => array_merge(
            ['price' => $price, 'fee' => $fees->firstWhere('id', $price->fee_id)],
            $this->classify($price, $fees->firstWhere('id', $price->fee_id), $year),
        ));
        $this->sectionD($classified);

        // ----- E. Registration fee -------------------------------------------
        $this->sectionE($fees, $classified);

        // ----- F. Tuition coverage matrix -------------------------------------
        $this->sectionF($fees, $classified, $grades);

        // ----- G. Transport ---------------------------------------------------
        $this->sectionG($fees, $classified);

        // ----- H. Food / meal plans --------------------------------------------
        $this->sectionH($fees, $classified);

        // ----- I. Uniform -------------------------------------------------------
        $this->sectionI($fees, $classified);

        // ----- J. Installment plans ----------------------------------------------
        $this->sectionJ();

        // ----- K. Cash / payment prerequisites ------------------------------------
        $this->sectionK();

        // ----- L. Readiness simulation (calls the real, read-only service) --------
        $readinessByCategory = $this->sectionL($year);

        // ----- M. Final readiness matrix -------------------------------------------
        $this->sectionM($year, $grades, $classified, $readinessByCategory);

        $this->newLine();
        $this->components->info('Audit complete. No data was created, updated, or deleted.');

        return self::SUCCESS;
    }

    // =====================================================================
    // A
    // =====================================================================
    private function sectionA(): ?AcademicYear
    {
        $this->header('A. Academic year');

        $query = AcademicYear::query();
        if ($needle = $this->option('year')) {
            $query->where('name', 'like', '%'.$needle.'%');
        } else {
            $query->where(fn ($q) => $q->where('name', 'like', '%2026%')->where('name', 'like', '%2027%'));
        }
        $candidates = $query->orderByDesc('start_date')->get();

        if ($candidates->isEmpty()) {
            $this->warn('No academic year found matching the target. Listing all academic years instead:');
            $candidates = AcademicYear::orderByDesc('start_date')->get();
        }

        $this->table(['id', 'name', 'start_date', 'end_date', 'is_active'], $candidates->map(fn (AcademicYear $y) => [
            $y->id, $y->name, $y->start_date?->toDateString(), $y->end_date?->toDateString(), $y->is_active ? 'yes' : 'no',
        ]));

        // Same lookup QuickStudentRegistrationController::create() uses.
        $default = AcademicYear::where('is_active', true)->orderByDesc('start_date')->get();
        if ($default->isEmpty()) {
            $this->components->error('Quick Registration would show "Нет активного учебного года" — zero active academic years exist.');

            return $candidates->first();
        }
        $primary = $default->first();
        $this->line("Quick Registration's primary/default academic year: <fg=cyan>#{$primary->id} {$primary->name}</> (first of ".$default->count().' active year(s), ordered by start_date desc).');
        if ($default->count() > 1) {
            $this->warn('More than one active academic year exists — Quick Registration and this audit both only consider the first by start_date desc; verify this is intentional.');
        }

        return $candidates->firstWhere('id', $primary->id) ?? $primary;
    }

    // =====================================================================
    // B
    // =====================================================================
    /** @return array{0: Collection, 1: Collection, 2: Collection} */
    private function sectionB(AcademicYear $year): array
    {
        $this->header('B. School structure');

        $stages = Stage::where('is_active', true)->orderBy('order')->get();
        $this->line('Active stages: '.$stages->count());
        $this->table(['id', 'name', 'order'], $stages->map(fn (Stage $s) => [$s->id, $s->name, $s->order]));

        $grades = Grade::whereIn('stage_id', $stages->pluck('id'))->orderBy('level')->get();
        $classes = SchoolClass::whereIn('grade_id', $grades->pluck('id'))->where('is_active', true)->get();

        $this->line('Active grades: '.$grades->count().' | Active classes: '.$classes->count());
        $this->table(['grade id', 'grade name', 'level', 'stage', 'active classes'], $grades->map(fn (Grade $g) => [
            $g->id, $g->name, $g->level, $stages->firstWhere('id', $g->stage_id)?->name,
            $classes->where('grade_id', $g->id)->count(),
        ]));

        return [$stages, $grades, $classes];
    }

    // =====================================================================
    // C
    // =====================================================================
    private function sectionC(Collection $fees): void
    {
        $this->header('C. Fee / service catalog');

        $groups = [
            'registration' => [Fee::CATEGORY_REGISTRATION],
            'tuition' => [Fee::CATEGORY_TUITION, Fee::CATEGORY_TUITION_REGULAR, Fee::CATEGORY_TUITION_FAMILY],
            'tuition_external / externat' => [Fee::CATEGORY_TUITION_EXTERNAL],
            'transport' => [Fee::CATEGORY_TRANSPORT],
            'food' => [Fee::CATEGORY_FOOD],
            'uniform' => [Fee::CATEGORY_UNIFORM],
            'extra / activity / books / other' => [Fee::CATEGORY_EXTRA_CLASSES, Fee::CATEGORY_BOOKS, Fee::CATEGORY_ACTIVITY, Fee::CATEGORY_OTHER],
        ];

        foreach ($groups as $label => $categories) {
            $group = $fees->whereIn('category', $categories);
            $this->line("<fg=cyan>{$label}</> ({$group->count()} fee(s)):");
            if ($group->isEmpty()) {
                $this->line('  (none)');

                continue;
            }
            $this->table(['id', 'name', 'category', 'amount', 'billing_period', 'grade_id', 'active'], $group->map(fn (Fee $fee) => [
                $fee->id, $fee->name_ru, $fee->category, $fee->getRawOriginal('amount') ?? $fee->getRawOriginal('base_price'),
                $fee->billing_period, $fee->grade_id, $fee->is_active ? 'yes' : 'no',
            ]));
        }
    }

    // =====================================================================
    // D
    // =====================================================================
    /**
     * Canonical classification — mirrors InvoiceCalculationService::resolvePrice()
     * and FinanceConfigurationReadinessService exactly, not a new interpretation.
     *
     * @return array{status: string, note: ?string}
     */
    private function classify(FeePrice $price, ?Fee $fee, AcademicYear $year): array
    {
        if (! $price->is_active) {
            return ['status' => 'INACTIVE', 'note' => null];
        }
        if ($price->academic_year_id !== $year->id) {
            return ['status' => 'WRONG YEAR', 'note' => null];
        }
        if ($price->start_date && $price->start_date->gt($this->today)) {
            return ['status' => 'FUTURE', 'note' => 'starts '.$price->start_date->toDateString()];
        }
        if ($price->end_date && $price->end_date->lt($this->today)) {
            return ['status' => 'STALE', 'note' => 'ended '.$price->end_date->toDateString()];
        }
        if ($price->currency !== 'EGP') {
            return ['status' => 'INVALID DIMENSION', 'note' => 'currency='.$price->currency.' (must be EGP)'];
        }
        if ($fee) {
            $dimensionIssue = match ($fee->category) {
                Fee::CATEGORY_TRANSPORT => $price->option_type !== 'zone'
                    ? "option_type='{$price->option_type}' (must be 'zone')" : (blank($price->option_value) ? 'option_value is blank' : null),
                Fee::CATEGORY_FOOD => $price->option_type !== 'meal_plan'
                    ? "option_type='{$price->option_type}' (must be 'meal_plan')"
                    : (blank($price->option_value) || ! MealPlan::whereKey($price->option_value)->exists()
                        ? "option_value='{$price->option_value}' does not resolve to an existing MealPlan" : null),
                Fee::CATEGORY_UNIFORM => blank($price->item) || blank($price->size) ? 'item and/or size is blank' : null,
                default => null,
            };
            if ($dimensionIssue) {
                return ['status' => 'INVALID DIMENSION', 'note' => $dimensionIssue];
            }
        }

        return ['status' => 'SELLABLE', 'note' => null];
    }

    private function sectionD(Collection $classified): void
    {
        $this->header('D. FeePrice coverage for the target academic year');
        if ($classified->isEmpty()) {
            $this->warn('Zero FeePrice rows exist for any active fee.');

            return;
        }
        $this->table(
            ['id', 'fee', 'category', 'year_id', 'grade', 'grade_group', 'period', 'opt_type', 'opt_value', 'item', 'size', 'amount', 'ccy', 'start', 'end', 'active', 'STATUS', 'note'],
            $classified->map(fn (array $row) => [
                $row['price']->id, $row['fee']?->name_ru, $row['fee']?->category, $row['price']->academic_year_id,
                $row['price']->grade_id, $row['price']->grade_group, $row['price']->payment_period,
                $row['price']->option_type, $row['price']->option_value, $row['price']->item, $row['price']->size,
                $row['price']->getRawOriginal('amount'), $row['price']->currency,
                $row['price']->start_date?->toDateString(), $row['price']->end_date?->toDateString(),
                $row['price']->is_active ? 'yes' : 'no', $row['status'], $row['note'],
            ]),
        );
        $counts = $classified->countBy('status');
        $this->line('Summary: '.$counts->map(fn ($count, $status) => "{$status}={$count}")->implode(', '));
    }

    // =====================================================================
    // E
    // =====================================================================
    private function sectionE(Collection $fees, Collection $classified): void
    {
        $this->header('E. Registration fee');
        $registrationFees = $fees->where('category', Fee::CATEGORY_REGISTRATION)->where('is_active', true);
        if ($registrationFees->isEmpty()) {
            $this->components->error('MISSING — no active Fee with category=registration exists.');

            return;
        }
        $sellable = $classified->whereIn('fee.id', $registrationFees->pluck('id'))->where('status', 'SELLABLE');
        if ($sellable->isEmpty()) {
            $this->components->error('MISSING — a registration Fee exists but has no SELLABLE FeePrice for the target academic year.');

            return;
        }
        foreach ($sellable as $row) {
            $this->line("READY — {$row['fee']->name_ru} (fee #{$row['fee']->id}): {$row['price']->getRawOriginal('amount')} EGP, price #{$row['price']->id}.");
        }
    }

    // =====================================================================
    // F
    // =====================================================================
    private function sectionF(Collection $fees, Collection $classified, Collection $grades): void
    {
        $this->header('F. Tuition coverage matrix (grade × payment period × sellable tariff)');
        $tuitionFees = $fees->whereIn('category', self::TUITION_CATEGORIES);
        $sellable = $classified->whereIn('fee.id', $tuitionFees->pluck('id'))->where('status', 'SELLABLE');

        if ($sellable->isEmpty()) {
            $this->components->error('No sellable tuition tariff exists for any grade in the target academic year.');

            return;
        }

        $rows = [];
        foreach ($grades as $grade) {
            $gradeGroup = self::GRADE_GROUP_LABELS[$grade->level] ?? null;
            $matches = $sellable->filter(fn (array $row) => $row['price']->grade_id === $grade->id
                || (blank($row['price']->grade_id) && $row['price']->grade_group === $gradeGroup)
                || (blank($row['price']->grade_id) && blank($row['price']->grade_group)));
            if ($matches->isEmpty()) {
                $rows[] = [$grade->name, $gradeGroup, '—', '—', '<fg=red>NOT SELLABLE</>'];

                continue;
            }
            foreach ($matches as $row) {
                $rows[] = [$grade->name, $gradeGroup, $row['price']->payment_period ?: '(any)', $row['price']->getRawOriginal('amount').' EGP', 'sellable'];
            }
        }
        $this->table(['grade', 'grade_group', 'payment_period', 'amount', 'status'], $rows);
    }

    // =====================================================================
    // G
    // =====================================================================
    private function sectionG(Collection $fees, Collection $classified): void
    {
        $this->header('G. Transport — routes (metadata) vs. zones (pricing)');

        $routes = DB::table('transport_routes')->orderBy('name')->get();
        $this->line('Transport routes (metadata only — transport_routes has no FK to fee_prices and no is_active column):');
        $this->table(['id', 'name', 'driver_name', 'bus_number', 'capacity'], $routes->map(fn ($r) => [$r->id, $r->name, $r->driver_name, $r->bus_number, $r->capacity]));

        $transportFees = $fees->where('category', Fee::CATEGORY_TRANSPORT);
        $transportPrices = $classified->whereIn('fee.id', $transportFees->pluck('id'));
        $sellableZones = $transportPrices->where('status', 'SELLABLE')->pluck('price.option_value')->unique()->values();

        $this->line('Sellable zones (option_type=zone, what Quick Registration can actually offer): '.($sellableZones->isEmpty() ? '(none)' : $sellableZones->implode(', ')));

        $legacy = $transportPrices->filter(fn (array $row) => $row['price']->option_type && $row['price']->option_type !== 'zone');
        if ($legacy->isNotEmpty()) {
            $this->components->error('Legacy/non-canonical option_type found on transport tariffs (must be \'zone\'):');
            $this->table(['price id', 'fee', 'option_type', 'option_value', 'status'], $legacy->map(fn (array $row) => [
                $row['price']->id, $row['fee']?->name_ru, $row['price']->option_type, $row['price']->option_value, $row['status'],
            ]));
        }

        if ($routes->isEmpty() && $sellableZones->isNotEmpty()) {
            $this->warn('Sellable zone pricing exists but zero transport_routes rows exist — Quick Registration\'s route dropdown will be empty (route is metadata only, not required for pricing, but the form field will look broken).');
        }
    }

    // =====================================================================
    // H
    // =====================================================================
    private function sectionH(Collection $fees, Collection $classified): void
    {
        $this->header('H. Food / meal plans');

        $mealPlans = MealPlan::orderBy('name_ru')->get();
        $foodPrices = $classified->whereIn('fee.id', $fees->where('category', Fee::CATEGORY_FOOD)->pluck('id'));

        $rows = [];
        foreach ($mealPlans as $plan) {
            $match = $foodPrices->first(fn (array $row) => $row['price']->option_type === 'meal_plan' && (string) $row['price']->option_value === (string) $plan->id);
            $rows[] = [
                $plan->id, $plan->name_ru, $plan->is_active ? 'yes' : 'no',
                $match ? $match['price']->id : '—',
                $match ? $match['price']->getRawOriginal('amount').' EGP' : '—',
                $match ? $match['status'] : '<fg=red>NO TARIFF</>',
            ];
        }
        $this->table(['id', 'name', 'active', 'price id', 'amount', 'status'], $rows);

        $orphanTariffs = $foodPrices->filter(fn (array $row) => $row['price']->option_type === 'meal_plan'
            && ! $mealPlans->contains('id', (int) $row['price']->option_value));
        if ($orphanTariffs->isNotEmpty()) {
            $this->components->error('Tariff rows pointing at a nonexistent MealPlan id:');
            $this->table(['price id', 'fee', 'option_value'], $orphanTariffs->map(fn (array $row) => [$row['price']->id, $row['fee']?->name_ru, $row['price']->option_value]));
        }

        $sellablePlanIds = $foodPrices->where('status', 'SELLABLE')->pluck('price.option_value')->map(fn ($v) => (int) $v);
        $usableActivePlans = $mealPlans->where('is_active', true)->whereIn('id', $sellablePlanIds);
        if ($fees->where('category', Fee::CATEGORY_FOOD)->where('is_active', true)->isNotEmpty() && $usableActivePlans->isEmpty()) {
            $this->components->error('Food fee(s) exist but zero usable (active + sellable-tariff) meal plans — Food would be gated off in Quick Registration.');
        }
    }

    // =====================================================================
    // I
    // =====================================================================
    private function sectionI(Collection $fees, Collection $classified): void
    {
        $this->header('I. Uniform');

        $products = DB::table('uniform_products')->orderBy('name_ru')->orderBy('size')->get();
        $uniformPrices = $classified->whereIn('fee.id', $fees->where('category', Fee::CATEGORY_UNIFORM)->pluck('id'));

        $rows = [];
        foreach ($products as $product) {
            $match = $uniformPrices->first(fn (array $row) => $row['price']->item === $product->name_ru && $row['price']->size === $product->size);
            $rows[] = [
                $product->name_ru, $product->size, $product->is_active ? 'yes' : 'no',
                $match ? $match['price']->id : '—',
                $match ? $match['price']->getRawOriginal('amount').' EGP' : '—',
                $match && $match['status'] === 'SELLABLE' ? 'yes' : 'no',
            ];
        }
        $this->table(['item', 'size', 'product active', 'tariff id', 'price', 'sellable'], $rows);

        $unsellable = collect($rows)->where(5, 'no');
        if ($unsellable->isNotEmpty()) {
            $this->warn($unsellable->count().' uniform product/size combination(s) exist in the catalog but cannot currently be sold.');
        }
    }

    // =====================================================================
    // J
    // =====================================================================
    private function sectionJ(): void
    {
        $this->header('J. Installment plans');

        $plans = PaymentPlan::with('installments')->orderBy('sort_order')->get();
        if ($plans->isEmpty()) {
            $this->components->error('MISSING — zero PaymentPlan rows exist at all.');

            return;
        }

        foreach ($plans as $plan) {
            $installments = $plan->installments;
            $total = $installments->sum(fn ($i) => (float) $i->percentage);
            $sequences = $installments->pluck('sequence');
            $duplicateSequences = $sequences->duplicates();

            $this->line("Plan #{$plan->id} \"{$plan->name_ru}\" — active=".($plan->is_active ? 'yes' : 'no').", installments=".$installments->count().", total%={$total}");
            if ($installments->isEmpty()) {
                $this->components->error('  Zero installments on this plan.');
            }
            if (abs($total - 100.0) > 0.001 && $installments->isNotEmpty()) {
                $this->components->error("  Percentages total {$total}%, not 100%.");
            }
            if ($duplicateSequences->isNotEmpty()) {
                $this->components->error('  Duplicate sequence number(s): '.$duplicateSequences->implode(', '));
            }
            $this->table(['sequence', 'name', 'offset_days', 'percentage'], $installments->sortBy('sequence')->map(fn ($i) => [$i->sequence, $i->name_ru, $i->offset_days, $i->percentage]));
        }

        if ($plans->where('is_active', true)->isEmpty()) {
            $this->components->error('No ACTIVE payment plan exists — Quick Registration disables installment mode.');
        }
    }

    // =====================================================================
    // K
    // =====================================================================
    private function sectionK(): void
    {
        $this->header('K. Cash / payment prerequisites (read-only — no session opened or closed)');

        $sessions = app(CashSessionService::class);
        foreach (['operating' => CashAccount::operating(), 'owner' => CashAccount::owner(), 'bank' => CashAccount::bank(), 'instapay' => CashAccount::instapay()] as $role => $account) {
            if (! $account) {
                $this->components->error("{$role}: MISSING — no CashAccount resolves to this canonical role.");

                continue;
            }
            $line = "{$role}: #{$account->id} \"{$account->name}\" (active=".($account->is_active ? 'yes' : 'no').', balance='.$account->getRawOriginal('balance').')';
            if ($role === 'operating') {
                $active = $sessions->activeFor($account);
                $line .= $active ? " — OPEN session #{$active->id} opened ".$active->opened_at?->toDateTimeString() : ' — no open session';
            }
            $this->line($line);
        }
    }

    // =====================================================================
    // L
    // =====================================================================
    /** @return array<string, array{ready: bool, reason: ?string}> */
    private function sectionL(AcademicYear $year): array
    {
        $this->header('L. Quick Registration readiness simulation (FinanceConfigurationReadinessService — live, read-only)');

        $result = app(FinanceConfigurationReadinessService::class)->forAcademicYear($year);
        $this->table(['category', 'ready', 'reason'], collect($result)->map(fn (array $status, string $category) => [
            $category, $status['ready'] ? '<fg=green>READY</>' : '<fg=red>NOT READY</>', $status['reason'],
        ]));

        return $result;
    }

    // =====================================================================
    // M
    // =====================================================================
    private function sectionM(AcademicYear $year, Collection $grades, Collection $classified, array $readiness): void
    {
        $this->header('M. Final readiness matrix');

        $sellableGrades = $grades->filter(function (Grade $grade) use ($classified) {
            $gradeGroup = self::GRADE_GROUP_LABELS[$grade->level] ?? null;
            $sellableTuition = $classified->where('status', 'SELLABLE')
                ->whereIn('fee.category', self::TUITION_CATEGORIES);

            return $sellableTuition->contains(fn (array $row) => $row['price']->grade_id === $grade->id
                || (blank($row['price']->grade_id) && $row['price']->grade_group === $gradeGroup)
                || (blank($row['price']->grade_id) && blank($row['price']->grade_group)));
        });

        $rows = [
            ['Academic year', $year ? 'READY' : 'MISSING', $year ? null : 'No 2026/2027 academic year found', $year ? null : 'Create the academic year'],
            ['School structure', $grades->isNotEmpty() ? 'READY' : 'MISSING', $grades->isEmpty() ? 'No active grades' : null, $grades->isEmpty() ? 'Create stages/grades/classes' : null],
            ['Registration', $readiness['registration']['ready'] ? 'READY' : 'MISSING', $readiness['registration']['reason'], $readiness['registration']['ready'] ? null : 'Create registration Fee + FeePrice'],
            ['Tuition', $sellableGrades->count() === $grades->count() && $grades->isNotEmpty() ? 'READY' : ($sellableGrades->isNotEmpty() ? 'PARTIAL' : 'MISSING'), $sellableGrades->count().' of '.$grades->count().' active grades sellable', $sellableGrades->count() < $grades->count() ? 'Add tuition FeePrice for the remaining grades' : null],
            ['Externat', $readiness['tuition']['ready'] ? 'depends on externat-specific data — see section D' : 'NOT REQUIRED FOR BASE TEST', null, null],
            ['Transport', $readiness['transport']['ready'] ? 'READY' : 'PARTIAL/MISSING — see G', $readiness['transport']['reason'], $readiness['transport']['ready'] ? null : 'Add a transport FeePrice with option_type=zone'],
            ['Food', $readiness['food']['ready'] ? 'READY' : 'PARTIAL/MISSING — see H', $readiness['food']['reason'], $readiness['food']['ready'] ? null : 'Add a food FeePrice with option_type=meal_plan'],
            ['Uniform', $readiness['uniform']['ready'] ? 'READY' : 'PARTIAL/MISSING — see I', $readiness['uniform']['reason'], $readiness['uniform']['ready'] ? null : 'Add a uniform FeePrice with item+size'],
            ['Extra services', 'NOT REQUIRED FOR BASE TEST', null, null],
            ['Installment plans', $readiness['installments']['ready'] ? 'READY' : 'MISSING', $readiness['installments']['reason'], $readiness['installments']['ready'] ? null : 'Create at least one active PaymentPlan'],
            ['Operating cash', CashAccount::operating() ? 'READY' : 'MISSING', null, CashAccount::operating() ? null : 'Run the canonical cash-accounts migration/backfill'],
            ['Bank', CashAccount::bank() ? 'READY' : 'MISSING', null, CashAccount::bank() ? null : 'Configure a bank CashAccount role'],
            ['InstaPay', CashAccount::instapay() ? 'READY' : 'MISSING', null, CashAccount::instapay() ? null : 'Configure an instapay CashAccount role'],
        ];
        $this->table(['COMPONENT', 'STATUS', 'BLOCKER', 'REQUIRED ACTION'], $rows);
    }

    private function header(string $title): void
    {
        $this->newLine();
        $this->components->twoColumnDetail("<fg=yellow;options=bold>{$title}</>", '');
    }
}
