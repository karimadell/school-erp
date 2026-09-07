<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Synchronizes ONLY the `uniform_products` catalog (physical SKU identity —
 * item + exact size) from the currently active, canonical exact-size
 * Uniform FeePrice rows of one explicit AcademicYear.
 *
 * This service NEVER reads or writes FeePrice — FeePrice remains the sole
 * pricing authority at invoice time (InvoiceCalculationService resolves
 * Uniform prices by fee_id + academic_year_id + item + size, never by
 * uniform_products.price — see UniformProductCatalogSyncServiceTest for a
 * direct proof). `uniform_products.price` is written here only because the
 * existing schema requires a non-null value; it is catalog metadata only,
 * never consulted for billing.
 *
 * Year resolution is the caller's responsibility — this service accepts an
 * AcademicYear model instance and never resolves one by name. The local
 * database currently contains two AcademicYear rows sharing the exact name
 * "2026/2027" (one canonical/active, one an empty accidental duplicate) —
 * any name-based lookup would be genuinely ambiguous here.
 *
 * Fails closed (writes nothing) unless the source Uniform FeePrice data for
 * the supplied year forms the EXACT expected Cartesian matrix: each of the
 * 4 canonical items at each of the 10 canonical exact sizes, with EXACTLY
 * ONE active FeePrice per pair — no missing pair, no duplicate/ambiguous
 * pair. Legacy grouped-size rows (6–10 / 12–16 / от S) are never read at
 * all, regardless of their active state.
 *
 * Stale catalog rows (an existing active `uniform_products` row whose
 * (name_ru, size) is not part of the validated current matrix) are
 * deactivated, never deleted — invoice_items.metadata and the Uniform
 * procurement report both capture item/size as an immutable snapshot at
 * sale time and never re-join the live catalog, so deactivating a stale
 * row cannot alter any historical record.
 *
 * Because `uniform_products` has no unique database constraint on
 * (name_ru, size), duplicate existing rows for the same identity are a
 * real possibility this service must detect and refuse to guess between —
 * it fails closed rather than silently picking one.
 */
class UniformProductCatalogSyncService
{
    public const CANONICAL_ITEMS = ['Комплект', 'Майка', 'Поло', 'Толстовка'];

    public const CANONICAL_SIZES = ['6', '8', '10', '12', '14', '16', 'S', 'M', 'L', 'XL'];

    private const LEGACY_SIZES = ['6–10', '12–16', 'от S'];

    private const CATALOG_CATEGORY = 'uniform';

    /**
     * @return array{
     *     academic_year_id: int,
     *     uniform_fee_id: ?int,
     *     expected_pairs: int,
     *     source_pairs: int,
     *     created: int,
     *     reactivated: int,
     *     updated: int,
     *     unchanged: int,
     *     deactivated: int,
     *     missing: array<int, string>,
     *     ambiguous_source_pairs: array<int, string>,
     *     duplicate_catalog_pairs: array<int, string>,
     *     applied: bool,
     * }
     */
    public function sync(AcademicYear $year, bool $apply = false): array
    {
        $result = [
            'academic_year_id' => $year->id,
            'uniform_fee_id' => null,
            'expected_pairs' => count(self::CANONICAL_ITEMS) * count(self::CANONICAL_SIZES),
            'source_pairs' => 0,
            'created' => 0,
            'reactivated' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'deactivated' => 0,
            'missing' => [],
            'ambiguous_source_pairs' => [],
            'duplicate_catalog_pairs' => [],
            'applied' => false,
        ];

        $fee = $this->resolveOperationalUniformFee();
        $result['uniform_fee_id'] = $fee?->id;

        if (! $fee) {
            return $result;
        }

        $sourcePairs = $this->loadSourcePairs($fee, $year);
        $result['source_pairs'] = $sourcePairs->count();
        $result['ambiguous_source_pairs'] = $this->findAmbiguousSourcePairs($fee, $year);
        $result['missing'] = $this->findMissingPairs($sourcePairs);

        if ($result['ambiguous_source_pairs'] !== [] || $result['missing'] !== []) {
            return $result;
        }

        $result['duplicate_catalog_pairs'] = $this->findDuplicateCatalogPairs();

        if ($result['duplicate_catalog_pairs'] !== []) {
            return $result;
        }

        $plan = $this->planCatalogChanges($sourcePairs);

        $result['created'] = count($plan['create']);
        $result['reactivated'] = count($plan['reactivate']);
        $result['updated'] = count($plan['update']);
        $result['unchanged'] = count($plan['unchanged']);
        $result['deactivated'] = count($plan['deactivate']);

        if (! $apply) {
            return $result;
        }

        DB::transaction(function () use ($plan): void {
            foreach ($plan['create'] as $pair) {
                DB::table('uniform_products')->insert([
                    'name_ru' => $pair['item'],
                    'name_ar' => null,
                    'category' => self::CATALOG_CATEGORY,
                    'size' => $pair['size'],
                    'price' => $pair['amount'],
                    'stock' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($plan['reactivate'] as $pair) {
                DB::table('uniform_products')->where('id', $pair['existing_id'])->update([
                    'is_active' => true,
                    'price' => $pair['amount'],
                    'updated_at' => now(),
                ]);
            }

            foreach ($plan['update'] as $pair) {
                DB::table('uniform_products')->where('id', $pair['existing_id'])->update([
                    'price' => $pair['amount'],
                    'updated_at' => now(),
                ]);
            }

            foreach ($plan['deactivate'] as $id) {
                DB::table('uniform_products')->where('id', $id)->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
            }
        });

        $result['applied'] = true;

        return $result;
    }

    /**
     * Category-first resolution — never by name_ru — identical convention
     * to SchoolPriceListImportService::resolveOperationalUniformFee(): an
     * operator's ad-hoc test fixture (is_test_data=true) must never be
     * selected as "the" real Uniform service, and more than one eligible
     * candidate is a fail-closed condition, never an automatic guess.
     */
    private function resolveOperationalUniformFee(): ?Fee
    {
        $candidates = Fee::where('category', Fee::CATEGORY_UNIFORM)
            ->where('is_test_data', false)
            ->get();

        if ($candidates->count() > 1) {
            throw new RuntimeException('Найдено несколько операционных услуг категории «uniform» — автоматическая синхронизация невозможна, требуется ручное вмешательство.');
        }

        return $candidates->first();
    }

    /** @return \Illuminate\Support\Collection<string, FeePrice> keyed by "item|size" */
    private function loadSourcePairs(Fee $fee, AcademicYear $year): \Illuminate\Support\Collection
    {
        return FeePrice::query()
            ->where('fee_id', $fee->id)
            ->where('academic_year_id', $year->id)
            ->where('is_active', true)
            ->whereIn('item', self::CANONICAL_ITEMS)
            ->whereIn('size', self::CANONICAL_SIZES)
            ->get()
            ->keyBy(fn (FeePrice $price) => $price->item.'|'.$price->size);
    }

    /**
     * A pair is ambiguous when more than one active FeePrice row exists for
     * the exact same (item, size) — keyBy() in loadSourcePairs() would
     * silently keep only the last one, so ambiguity must be detected
     * separately, before that collapsing ever matters.
     *
     * @return array<int, string>
     */
    private function findAmbiguousSourcePairs(Fee $fee, AcademicYear $year): array
    {
        return FeePrice::query()
            ->where('fee_id', $fee->id)
            ->where('academic_year_id', $year->id)
            ->where('is_active', true)
            ->whereIn('item', self::CANONICAL_ITEMS)
            ->whereIn('size', self::CANONICAL_SIZES)
            ->get()
            ->groupBy(fn (FeePrice $price) => $price->item.'|'.$price->size)
            ->filter(fn ($group) => $group->count() > 1)
            ->keys()
            ->values()
            ->all();
    }

    /** @param \Illuminate\Support\Collection<string, FeePrice> $sourcePairs
     *  @return array<int, string> */
    private function findMissingPairs($sourcePairs): array
    {
        $missing = [];
        foreach (self::CANONICAL_ITEMS as $item) {
            foreach (self::CANONICAL_SIZES as $size) {
                $key = $item.'|'.$size;
                if (! $sourcePairs->has($key)) {
                    $missing[] = $key;
                }
            }
        }

        return $missing;
    }

    /**
     * There is no database-level unique constraint on (name_ru, size), so
     * more than one existing uniform_products row sharing the same
     * identity is a real, detectable possibility this service must never
     * silently resolve by guessing (e.g. "first" or "most recent").
     *
     * @return array<int, string>
     */
    private function findDuplicateCatalogPairs(): array
    {
        return DB::table('uniform_products')
            ->where('category', self::CATALOG_CATEGORY)
            ->select('name_ru', 'size', DB::raw('count(*) as c'))
            ->groupBy('name_ru', 'size')
            ->havingRaw('count(*) > 1')
            ->get()
            ->map(fn ($row) => $row->name_ru.'|'.$row->size)
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<string, FeePrice>  $sourcePairs
     * @return array{
     *     create: array<int, array{item:string, size:string, amount:string}>,
     *     reactivate: array<int, array{item:string, size:string, amount:string, existing_id:int}>,
     *     update: array<int, array{amount:string, existing_id:int}>,
     *     unchanged: array<int, string>,
     *     deactivate: array<int, int>,
     * }
     */
    private function planCatalogChanges($sourcePairs): array
    {
        $existingProducts = DB::table('uniform_products')
            ->where('category', self::CATALOG_CATEGORY)
            ->get()
            ->keyBy(fn ($row) => $row->name_ru.'|'.$row->size);

        $create = [];
        $reactivate = [];
        $update = [];
        $unchanged = [];

        foreach ($sourcePairs as $key => $price) {
            $existing = $existingProducts->get($key);
            $amount = (string) $price->getRawOriginal('amount');

            if (! $existing) {
                $create[] = ['item' => $price->item, 'size' => $price->size, 'amount' => $amount];

                continue;
            }

            if (! $existing->is_active) {
                $reactivate[] = ['item' => $price->item, 'size' => $price->size, 'amount' => $amount, 'existing_id' => $existing->id];

                continue;
            }

            if (bccomp((string) $existing->price, $amount, 2) !== 0) {
                $update[] = ['amount' => $amount, 'existing_id' => $existing->id];

                continue;
            }

            $unchanged[] = $key;
        }

        $deactivate = $existingProducts
            ->filter(fn ($row) => $row->category === self::CATALOG_CATEGORY
                && $row->is_active
                && ! $sourcePairs->has($row->name_ru.'|'.$row->size))
            ->pluck('id')
            ->values()
            ->all();

        return [
            'create' => $create,
            'reactivate' => $reactivate,
            'update' => $update,
            'unchanged' => $unchanged,
            'deactivate' => $deactivate,
        ];
    }
}
