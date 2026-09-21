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
 * Food Phase 4B corrective (owner-approved): the previous "3 legacy
 * a-la-carte names are permanently excluded from the MealPlan model"
 * decision is superseded. All six Food names — Комплексное питание,
 * Завтрак, Обед, Суп, Второе блюдо, Напиток — are now migrated
 * identically: a real MealPlan is created/reused by natural key
 * (name_ru), and the matching FeePrice row's option_value is repointed
 * from its legacy textual name to that MealPlan's numeric id.
 * amount/fee_id/academic_year_id/grade_id/grade_group/payment_period/
 * start_date/end_date/option_type/is_active/currency and every other
 * FeePrice field are never touched — only option_value changes, and
 * only for rows whose option_value still exactly equals the legacy
 * name at the moment of the write (see applyAll()'s conditional
 * UPDATE — re-verified inside the transaction; if it no longer
 * matches, the whole apply aborts rather than risk an incorrect
 * write). This is a pure identity migration — legacy textual
 * option_value to numeric MealPlan id — and never writes amount, so
 * multiple matched FeePrice rows for the same Food name legitimately
 * disagreeing on amount (e.g. a mid-year price change across
 * non-overlapping date ranges — already a first-class, tested Food
 * pricing feature) is never treated as a conflict or a reason to
 * abort; every such row is still correctly repointed. Default mode is
 * dry-run: it computes and prints the full plan, including anything
 * already satisfied (SKIP). Nothing is written unless --apply is
 * passed, and the entire write is one DB transaction. Re-running
 * (dry-run or --apply) is idempotent — every entity is matched by a
 * natural key before deciding to create it.
 */
class UatMasterDataRepair extends Command
{
    protected $signature = 'finance:uat-master-data-repair
        {--year= : Exact academic year name to target, e.g. "2026/2027"}
        {--apply : Actually write changes. Default is dry-run — no writes.}';

    protected $description = 'UAT-only: idempotently create/link the minimum master data (transport routes, meal plans, uniform products, one test installment plan) Quick Registration needs — default dry-run, --apply required to write.';

    /**
     * name_ru => [meal_type, period] — all six operational Food names,
     * mapped to the MealPlan model's own existing enum domain (never an
     * invented value). Суп/Второе блюдо are individually purchasable
     * items associated with lunch (TYPE_LUNCH); Напиток is a drink
     * available across meal contexts, matching Комплексное питание's
     * own existing TYPE_BOTH usage. All six are daily, matching the
     * three already-established entries.
     */
    private const FOOD_MEAL_TYPE_MAP = [
        'Комплексное питание' => ['meal_type' => MealPlan::TYPE_BOTH, 'period' => MealPlan::PERIOD_DAILY],
        'Завтрак' => ['meal_type' => MealPlan::TYPE_BREAKFAST, 'period' => MealPlan::PERIOD_DAILY],
        'Обед' => ['meal_type' => MealPlan::TYPE_LUNCH, 'period' => MealPlan::PERIOD_DAILY],
        'Суп' => ['meal_type' => MealPlan::TYPE_LUNCH, 'period' => MealPlan::PERIOD_DAILY],
        'Второе блюдо' => ['meal_type' => MealPlan::TYPE_LUNCH, 'period' => MealPlan::PERIOD_DAILY],
        'Напиток' => ['meal_type' => MealPlan::TYPE_BOTH, 'period' => MealPlan::PERIOD_DAILY],
    ];

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
        $this->printRollbackInfo($foodPlan);

        if (! $apply) {
            $this->newLine();
            $this->components->warn('DRY-RUN ONLY — no data was created, updated, or deleted. Re-run with --apply to write.');

            return self::SUCCESS;
        }

        $this->applyAll($transportPlan, $foodPlan, $uniformPlan, $installmentPlan);

        $this->newLine();
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
        return AcademicYear::normalizeName($name);
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

        $names = array_keys(self::FOOD_MEAL_TYPE_MAP);
        $plan = [];

        foreach ($names as $name) {
            $matches = $rows->filter(fn (FeePrice $p) => $p->option_value === $name)->values();

            if ($matches->isEmpty()) {
                $plan[] = ['name' => $name, 'status' => 'NOT FOUND (no FeePrice row uses this legacy name)', 'fee_price_updates' => []];

                continue;
            }

            $existingPlan = MealPlan::where('name_ru', $name)->first();

            $plan[] = [
                'name' => $name,
                'status' => $existingPlan ? 'MEAL PLAN ALREADY EXISTS' : 'CREATE MEAL PLAN',
                'meal_type' => self::FOOD_MEAL_TYPE_MAP[$name]['meal_type'],
                'period' => self::FOOD_MEAL_TYPE_MAP[$name]['period'],
                // MealPlan.price is NOT authoritative for billing — every
                // invoice resolves its price from FeePrice directly (see
                // InvoiceCalculationService::priceFoodDailyLine()), which
                // legitimately supports multiple FeePrice rows for the
                // same Food identity within one academic year at
                // different amounts across non-overlapping date ranges
                // (a tested, first-class feature — see
                // FoodDailyBillingTest's tariff-segmentation tests).
                // Differing amounts across $matches are therefore never
                // an identity conflict for this repair — this deterministic
                // "first match" pick (the same established convention
                // already used for the pre-existing 3 Food names) only
                // seeds a cosmetic display field; it never affects what
                // any invoice actually charges.
                'price' => $matches->first()->getRawOriginal('amount'),
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
        // Corrective pass P1 (code review) — only CURRENTLY SELLABLE
        // (active) FeePrice rows should ever produce/reactivate a
        // uniform_products catalog entry. Without this filter, a legacy
        // grouped-size FeePrice deactivated by SchoolPriceListImportService
        // (e.g. '6–10') would still generate/reactivate an "active"
        // uniform_products row for a combination nothing can actually be
        // sold against anymore — a stale, misleading catalog entry, even
        // though Quick Registration's own selector independently
        // cross-checks against active FeePrice sellability and would
        // never actually offer it. This does not delete or deactivate any
        // existing uniform_products row — it only narrows which FeePrice
        // rows are read as the CURRENT source of truth here.
        $rows = FeePrice::whereIn('fee_id', $uniformFeeIds)
            ->where('academic_year_id', $year->id)
            ->whereNotNull('item')->whereNotNull('size')
            ->where('is_active', true)
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
            if (empty($entry['fee_price_updates'])) {
                $rows[] = [$entry['name'], $entry['status'], '—', '—'];

                continue;
            }
            foreach ($entry['fee_price_updates'] as $update) {
                $rows[] = [
                    $entry['name'],
                    $entry['status'],
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

    private function printRollbackInfo(array $foodPlan): void
    {
        $this->header('G. Rollback / reversal information');
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
                if (empty($entry['fee_price_updates'])) {
                    continue;
                }
                $plan = MealPlan::firstOrCreate(
                    ['name_ru' => $entry['name']],
                    ['meal_type' => $entry['meal_type'], 'period' => $entry['period'], 'price' => $entry['price'], 'is_active' => true],
                );
                foreach ($entry['fee_price_updates'] as $update) {
                    if ($update['already_linked']) {
                        continue;
                    }
                    // Re-verify inside the transaction that the row still
                    // holds the exact legacy value this plan was computed
                    // from — guards against a concurrent write between the
                    // plan read and this update. Anything other than
                    // exactly one affected row means the plan is stale, so
                    // abort the whole transaction rather than risk writing
                    // an option_value onto a row that no longer matches.
                    $affected = FeePrice::whereKey($update['fee_price_id'])
                        ->where('option_value', $update['before_option_value'])
                        ->update(['option_value' => (string) $plan->id]);

                    if ($affected !== 1) {
                        throw new \RuntimeException("Food repair aborted: fee_price #{$update['fee_price_id']} no longer matches its planned option_value '{$update['before_option_value']}' (expected exactly 1 affected row, got {$affected}).");
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
