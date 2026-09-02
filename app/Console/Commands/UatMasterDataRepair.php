<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\MealPlan;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4B — UAT-only master-data repair. Creates/links the minimum
 * catalog rows (transport_routes, MealPlan, uniform_products, one test
 * PaymentPlan) that Quick Registration needs to be fully submittable for
 * academic year 2026/2027, without touching any existing tariff, invoice,
 * payment, cash, or subscription data.
 *
 * Every value this command writes is either:
 *  - derived directly from existing 2026/2027 FeePrice rows (item, size,
 *    amount — never invented), or
 *  - an explicitly UAT-only placeholder (route names, the installment
 *    plan name) clearly prefixed "UAT —" so it can never be mistaken for
 *    real production master data.
 *
 * Default mode is dry-run: it computes and prints the full plan, including
 * anything already satisfied (SKIP) and the 3 legacy Food a-la-carte names
 * (Суп, Второе блюдо, Напиток) that are explicitly, permanently excluded
 * from the MealPlan model by a confirmed UAT decision — reported as
 * SKIPPED, never as a blocker, and never preventing the rest of --apply
 * from completing. Nothing is written unless --apply is passed, and the
 * entire write is one DB transaction. Re-running (dry-run or --apply) is
 * idempotent — every entity is matched by a natural key before deciding
 * to create it.
 */
class UatMasterDataRepair extends Command
{
    protected $signature = 'finance:uat-master-data-repair
        {--year= : Exact academic year name to target, e.g. "2026/2027"}
        {--apply : Actually write changes. Default is dry-run — no writes.}';

    protected $description = 'UAT-only: idempotently create/link the minimum master data (transport routes, meal plans, uniform products, one test installment plan) Quick Registration needs — default dry-run, --apply required to write.';

    /** name_ru => [meal_type, period] — the 3 legacy Food names that fit the MealPlan model shape. */
    private const FOOD_MEAL_TYPE_MAP = [
        'Комплексное питание' => ['meal_type' => MealPlan::TYPE_BOTH, 'period' => MealPlan::PERIOD_DAILY],
        'Завтрак' => ['meal_type' => MealPlan::TYPE_BREAKFAST, 'period' => MealPlan::PERIOD_DAILY],
        'Обед' => ['meal_type' => MealPlan::TYPE_LUNCH, 'period' => MealPlan::PERIOD_DAILY],
    ];

    /**
     * The 3 legacy Food names confirmed (UAT decision, Phase 4B) to be
     * a-la-carte components — not subscription-shaped MealPlans — and
     * therefore permanently excluded from MealPlan creation/linking. Never
     * mapped, never linked, their FeePrice rows never touched. Reported as
     * SKIPPED — informational, never a blocker to the rest of --apply.
     */
    private const FOOD_SKIPPED_LEGACY_NAMES = ['Суп', 'Второе блюдо', 'Напиток'];

    private const FOOD_SKIPPED_LEGACY_STATUS = 'SKIPPED — LEGACY A-LA-CARTE / OUTSIDE CURRENT QUICK REGISTRATION MEALPLAN MODEL';

    private const TRANSPORT_ROUTES = [
        'UAT — Зона 1 — Каусер, Мубарак 2, Интерконтиненталь',
        'UAT — Зона 2 — Арабия, Мадарес, Шератон',
        'UAT — Зона 3 — Мубарак 7, Эль-Хеляль, Эль-Ахья',
    ];

    private const INSTALLMENT_PLAN_NAME = 'UAT — 2 платежа 50/50';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->components->info('Phase 4B — UAT Master Data Repair ('.($apply ? 'APPLY' : 'DRY-RUN — no writes').')');

        $year = $this->resolveYear();
        if (! $year) {
            $this->components->error('No matching academic year found — nothing to plan.');

            return self::FAILURE;
        }
        $this->line("Target academic year: <fg=cyan>#{$year->id} {$year->name}</> ({$year->start_date->toDateString()} – {$year->end_date->toDateString()})");

        $transportPlan = $this->planTransport();
        $foodPlan = $this->planFood($year);
        $uniformPlan = $this->planUniform($year);
        $installmentPlan = $this->planInstallments();

        $this->printTransportPlan($transportPlan);
        $this->printFoodPlan($foodPlan);
        $this->printUniformPlan($uniformPlan);
        $this->printInstallmentPlan($installmentPlan);
        $this->printSkippedLegacyNotes($foodPlan);
        $this->printRollbackInfo($foodPlan);

        if (! $apply) {
            $this->newLine();
            $this->components->warn('DRY-RUN ONLY — no data was created, updated, or deleted. Re-run with --apply to write.');

            return self::SUCCESS;
        }

        $this->applyAll($transportPlan, $foodPlan, $uniformPlan, $installmentPlan);

        $skippedLegacy = collect($foodPlan)->where('skipped_legacy', true);
        $this->newLine();
        if ($skippedLegacy->isNotEmpty()) {
            $this->line($skippedLegacy->count().' Food name(s) SKIPPED — LEGACY A-LA-CARTE, left exactly as-is (this does not affect the rest of this run): '.$skippedLegacy->pluck('name')->implode(', '));
        }
        $this->components->info('Apply complete. Nothing outside transport_routes / meal_plans / uniform_products / payment_plans / payment_plan_installments / the linked fee_prices.option_value fields was touched.');

        return self::SUCCESS;
    }

    // =====================================================================
    // Year resolution — identical exact-normalized-match rule as
    // finance:readiness-audit, so both tools can never disagree about
    // which year "2026/2027" means.
    // =====================================================================
    private function normalizeYearName(string $name): string
    {
        return preg_replace('/\s+/', '', $name);
    }

    private function resolveYear(): ?AcademicYear
    {
        if ($needle = $this->option('year')) {
            $normalized = $this->normalizeYearName($needle);

            return AcademicYear::all()
                ->first(fn (AcademicYear $y) => $this->normalizeYearName($y->name) === $normalized);
        }

        return AcademicYear::query()
            ->where(fn ($q) => $q->where('name', 'like', '%2026%')->where('name', 'like', '%2027%'))
            ->orderByDesc('start_date')->first();
    }

    // =====================================================================
    // Planning (pure reads — safe to call in any mode)
    // =====================================================================

    /** @return array<int, array{name: string, action: string, existing_id: ?int}> */
    private function planTransport(): array
    {
        $existing = DB::table('transport_routes')->pluck('id', 'name');

        return collect(self::TRANSPORT_ROUTES)->map(fn (string $name) => [
            'name' => $name,
            'action' => $existing->has($name) ? 'SKIP (already exists)' : 'CREATE',
            'existing_id' => $existing->get($name),
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function planFood(AcademicYear $year): array
    {
        $foodFeeIds = Fee::where('category', Fee::CATEGORY_FOOD)->pluck('id');
        $rows = FeePrice::whereIn('fee_id', $foodFeeIds)
            ->where('academic_year_id', $year->id)
            ->where('option_type', 'meal_plan')
            ->get();

        $names = array_merge(array_keys(self::FOOD_MEAL_TYPE_MAP), self::FOOD_SKIPPED_LEGACY_NAMES);
        $plan = [];

        foreach ($names as $name) {
            $matches = $rows->filter(fn (FeePrice $p) => $p->option_value === $name)->values();

            if ($matches->isEmpty()) {
                $plan[] = ['name' => $name, 'status' => 'NOT FOUND (no FeePrice row uses this legacy name)', 'skipped_legacy' => false, 'fee_price_updates' => []];

                continue;
            }

            if (in_array($name, self::FOOD_SKIPPED_LEGACY_NAMES, true)) {
                $plan[] = [
                    'name' => $name,
                    'status' => self::FOOD_SKIPPED_LEGACY_STATUS,
                    'skipped_legacy' => true,
                    'reason' => "confirmed UAT decision: '{$name}' is an a-la-carte item, not a subscription-shaped MealPlan — left exactly as-is (no deletion, no option_value rewrite, no invented enum value); does not block Transport/other Food links/Uniform/Installments",
                    'fee_price_ids' => $matches->pluck('id')->all(),
                    'fee_price_updates' => [],
                ];

                continue;
            }

            $existingPlan = MealPlan::where('name_ru', $name)->first();
            $amounts = $matches->pluck('amount')->unique();

            $plan[] = [
                'name' => $name,
                'status' => $existingPlan ? 'MEAL PLAN ALREADY EXISTS' : 'CREATE MEAL PLAN',
                'skipped_legacy' => false,
                'meal_type' => self::FOOD_MEAL_TYPE_MAP[$name]['meal_type'],
                'period' => self::FOOD_MEAL_TYPE_MAP[$name]['period'],
                'price' => $matches->first()->getRawOriginal('amount'),
                'amount_conflict' => $amounts->count() > 1,
                'existing_meal_plan_id' => $existingPlan?->id,
                'fee_price_updates' => $matches->map(fn (FeePrice $p) => [
                    'fee_price_id' => $p->id,
                    'before_option_value' => $p->option_value,
                    'amount' => $p->getRawOriginal('amount'),
                    'already_linked' => $existingPlan && $p->option_value === (string) $existingPlan->id,
                ])->all(),
            ];
        }

        return $plan;
    }

    /** @return array<int, array<string, mixed>> */
    private function planUniform(AcademicYear $year): array
    {
        $uniformFeeIds = Fee::where('category', Fee::CATEGORY_UNIFORM)->pluck('id');
        $rows = FeePrice::whereIn('fee_id', $uniformFeeIds)
            ->where('academic_year_id', $year->id)
            ->whereNotNull('item')->whereNotNull('size')
            ->get();

        $pairs = $rows->groupBy(fn (FeePrice $p) => $p->item.'|'.$p->size);
        $plan = [];

        foreach ($pairs as $key => $group) {
            [$item, $size] = explode('|', $key, 2);
            $activeProduct = DB::table('uniform_products')->where('name_ru', $item)->where('size', $size)->where('is_active', true)->first();
            $inactiveProduct = $activeProduct ? null : DB::table('uniform_products')->where('name_ru', $item)->where('size', $size)->where('is_active', false)->first();

            $plan[] = [
                'item' => $item,
                'size' => $size,
                'price' => $group->first()->getRawOriginal('amount'),
                'fee_price_ids' => $group->pluck('id')->all(),
                'status' => $activeProduct ? 'SKIP (active product exists)' : ($inactiveProduct ? 'REACTIVATE (inactive product exists)' : 'CREATE'),
                'existing_id' => $activeProduct->id ?? $inactiveProduct->id ?? null,
            ];
        }

        return $plan;
    }

    /** @return array{name: string, status: string, existing_id: ?int, installments: array<int, array{sequence:int, percentage:string, offset_days:int}>} */
    private function planInstallments(): array
    {
        $existing = PaymentPlan::where('name_ru', self::INSTALLMENT_PLAN_NAME)->first();

        return [
            'name' => self::INSTALLMENT_PLAN_NAME,
            'status' => $existing ? 'SKIP (already exists)' : 'CREATE',
            'existing_id' => $existing?->id,
            'installments' => [
                ['sequence' => 1, 'percentage' => '50.0000', 'offset_days' => 0],
                ['sequence' => 2, 'percentage' => '50.0000', 'offset_days' => 30],
            ],
        ];
    }

    // =====================================================================
    // Dry-run output
    // =====================================================================
    private function printTransportPlan(array $plan): void
    {
        $this->header('A. Transport routes (transport_routes — UAT placeholder names)');
        $this->table(['name', 'action', 'existing id'], collect($plan)->map(fn ($r) => [$r['name'], $r['action'], $r['existing_id']]));
    }

    private function printFoodPlan(array $plan): void
    {
        $this->header('B/C. Food — MealPlan creation and fee_prices.option_value linking');
        $rows = [];
        foreach ($plan as $entry) {
            if (empty($entry['fee_price_updates']) && ! ($entry['skipped_legacy'] ?? false)) {
                $rows[] = [$entry['name'], $entry['status'], '—', '—'];

                continue;
            }
            if ($entry['skipped_legacy'] ?? false) {
                $rows[] = [$entry['name'], self::FOOD_SKIPPED_LEGACY_STATUS, '—', 'see section G — fee_price ids: '.implode(',', $entry['fee_price_ids']).' (untouched)'];

                continue;
            }
            foreach ($entry['fee_price_updates'] as $update) {
                $rows[] = [
                    $entry['name'],
                    $entry['status'].($entry['amount_conflict'] ? ' [amounts differ across matched rows]' : ''),
                    "fee_price #{$update['fee_price_id']}: option_value BEFORE = '{$update['before_option_value']}'",
                    $update['already_linked'] ? 'already linked, no change' : "AFTER = numeric MealPlan id (amount {$update['amount']} EGP unchanged)",
                ];
            }
        }
        $this->table(['legacy name', 'meal plan status', 'fee_price update', 'result'], $rows);
    }

    private function printUniformPlan(array $plan): void
    {
        $this->header('D. Uniform products (uniform_products)');
        if (empty($plan)) {
            $this->line('No Uniform FeePrice rows with item+size found for this year.');

            return;
        }
        $this->table(['item', 'size', 'price (mirrors FeePrice)', 'fee_price ids', 'action'], collect($plan)->map(fn ($r) => [
            $r['item'], $r['size'], $r['price'], implode(',', $r['fee_price_ids']), $r['status'],
        ]));
    }

    private function printInstallmentPlan(array $plan): void
    {
        $this->header('E. Installment plan (payment_plans / payment_plan_installments — UAT test data only)');
        $this->line("Plan: \"{$plan['name']}\" — {$plan['status']}".($plan['existing_id'] ? " (#{$plan['existing_id']})" : ''));
        if ($plan['status'] === 'CREATE') {
            $this->table(['sequence', 'percentage', 'offset_days'], collect($plan['installments'])->map(fn ($i) => [$i['sequence'], $i['percentage'], $i['offset_days']]));
        }
    }

    private function printSkippedLegacyNotes(array $foodPlan): void
    {
        $this->header('G. Skipped legacy rows (informational — confirmed UAT decision, never blocks --apply)');
        $skipped = collect($foodPlan)->where('skipped_legacy', true);
        if ($skipped->isEmpty()) {
            $this->line('None.');

            return;
        }
        foreach ($skipped as $entry) {
            $this->line("- {$entry['name']}: {$entry['reason']} (fee_price ids: ".implode(',', $entry['fee_price_ids']).')');
        }
    }

    private function printRollbackInfo(array $foodPlan): void
    {
        $this->header('H. Rollback / reversal information');
        $this->line('- transport_routes / MealPlan / uniform_products / payment_plans rows created by --apply can be deleted directly (nothing references them yet: no FeePrice FK to transport_routes or uniform_products, no invoice/subscription created by this command).');
        $updates = collect($foodPlan)->flatMap(fn ($entry) => collect($entry['fee_price_updates'] ?? [])
            ->where('already_linked', false)
            ->map(fn ($u) => "  UPDATE fee_prices SET option_value = '{$u['before_option_value']}' WHERE id = {$u['fee_price_id']};"));
        if ($updates->isNotEmpty()) {
            $this->line('- to reverse the fee_prices.option_value links this run would make:');
            $updates->each(fn ($line) => $this->line($line));
        }
    }

    // =====================================================================
    // Apply (writes — only reached when --apply was passed)
    // =====================================================================
    private function applyAll(array $transportPlan, array $foodPlan, array $uniformPlan, array $installmentPlan): void
    {
        DB::transaction(function () use ($transportPlan, $foodPlan, $uniformPlan, $installmentPlan) {
            foreach ($transportPlan as $route) {
                if ($route['action'] === 'CREATE') {
                    DB::table('transport_routes')->insert([
                        'name' => $route['name'], 'driver_name' => null, 'bus_number' => null, 'capacity' => 0,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            foreach ($foodPlan as $entry) {
                if (($entry['skipped_legacy'] ?? false) || empty($entry['fee_price_updates'])) {
                    continue;
                }
                $plan = MealPlan::firstOrCreate(
                    ['name_ru' => $entry['name']],
                    ['meal_type' => $entry['meal_type'], 'period' => $entry['period'], 'price' => $entry['price'], 'is_active' => true],
                );
                foreach ($entry['fee_price_updates'] as $update) {
                    if (! $update['already_linked']) {
                        FeePrice::whereKey($update['fee_price_id'])->update(['option_value' => (string) $plan->id]);
                    }
                }
            }

            foreach ($uniformPlan as $entry) {
                if ($entry['status'] === 'CREATE') {
                    DB::table('uniform_products')->insert([
                        'name_ru' => $entry['item'], 'name_ar' => null, 'category' => 'uniform', 'size' => $entry['size'],
                        'price' => $entry['price'], 'stock' => null, 'is_active' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } elseif (str_starts_with($entry['status'], 'REACTIVATE')) {
                    DB::table('uniform_products')->whereKey($entry['existing_id'])->update(['is_active' => true, 'updated_at' => now()]);
                }
            }

            if ($installmentPlan['status'] === 'CREATE') {
                $plan = PaymentPlan::create([
                    'name_ru' => $installmentPlan['name'], 'is_active' => true, 'sort_order' => 0,
                    'description' => 'UAT test data only — not a final school policy.',
                    'is_test_data' => true,
                ]);
                foreach ($installmentPlan['installments'] as $installment) {
                    PaymentPlanInstallment::create(array_merge(
                        ['payment_plan_id' => $plan->id, 'name_ru' => 'Этап '.$installment['sequence']],
                        $installment,
                    ));
                }
            }
        });
    }

    private function header(string $title): void
    {
        $this->newLine();
        $this->components->twoColumnDetail("<fg=yellow;options=bold>{$title}</>", '');
    }
}
