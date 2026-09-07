<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\Fee;
use App\Models\FeePrice;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Normalizes ONLY `fee_prices.option_type` on active Transport FeePrice
 * rows for one explicit AcademicYear, from the known legacy value
 * ('Район', produced by a prior version of SchoolPriceListImportService
 * before its TRANSPORT_ZONE_OPTION_TYPE constant was corrected — see that
 * class's own docblock) to the current canonical value ('zone') that every
 * runtime pricing/readiness consumer already expects
 * (FinanceConfigurationReadinessService::assessTransport(),
 * InvoiceCalculationService's dimensional matching, Quick Registration's
 * controller/service).
 *
 * This is a pure field-level data correction, not a pricing change:
 * amount, option_value, payment_period, dates, and every other column are
 * left byte-identical. No FeePrice row is ever created or deleted, and no
 * inactive (historical) row is ever touched.
 *
 * `transport_routes` (the independent bus/logistics catalog) is
 * completely out of scope here — it has no relationship to option_type
 * and is never read or written by this service.
 *
 * Year resolution is the caller's responsibility — this service accepts
 * an AcademicYear model instance and never resolves one by name.
 *
 * Fails closed (writes nothing) if the active Transport FeePrice data for
 * the supplied year contains anything this service cannot safely reason
 * about on its own:
 *   - an option_type value that is neither the known legacy value nor the
 *     canonical value (an "unexpected" value — never silently normalized);
 *   - a legacy row whose (option_value, payment_period) identity already
 *     has an active canonical row too (renaming would create a duplicate
 *     canonical tariff);
 *   - more than one active legacy row sharing the same (option_value,
 *     payment_period) identity (ambiguous — which one is "the" tariff is
 *     not this service's decision to make);
 *   - a legacy row with a blank option_value.
 */
class TransportZoneOptionTypeNormalizationService
{
    public const LEGACY_OPTION_TYPE = 'Район';

    public const CANONICAL_OPTION_TYPE = 'zone';

    /**
     * @return array{
     *     academic_year_id: int,
     *     transport_fee_id: ?int,
     *     legacy_found: int,
     *     canonical_found: int,
     *     unexpected_found: int,
     *     eligible: int,
     *     normalized: int,
     *     conflicts: array<int, array{identity: string, legacy_ids: array<int,int>, canonical_ids: array<int,int>}>,
     *     unexpected_option_types: array<int, array{id: int, option_type: ?string}>,
     *     legacy_rows: array<int, array{id:int, option_value:string, payment_period:?string}>,
     *     applied: bool,
     * }
     */
    public function normalize(AcademicYear $year, bool $apply = false): array
    {
        $result = [
            'academic_year_id' => $year->id,
            'transport_fee_id' => null,
            'legacy_found' => 0,
            'canonical_found' => 0,
            'unexpected_found' => 0,
            'eligible' => 0,
            'normalized' => 0,
            'conflicts' => [],
            'unexpected_option_types' => [],
            'legacy_rows' => [],
            'applied' => false,
        ];

        $fee = $this->resolveOperationalTransportFee();
        $result['transport_fee_id'] = $fee?->id;

        if (! $fee) {
            return $result;
        }

        $activePrices = FeePrice::query()
            ->where('fee_id', $fee->id)
            ->where('academic_year_id', $year->id)
            ->where('is_active', true)
            ->whereNotNull('option_type')
            ->get();

        $legacy = $activePrices->filter(fn (FeePrice $p) => $p->option_type === self::LEGACY_OPTION_TYPE)->values();
        $canonical = $activePrices->filter(fn (FeePrice $p) => $p->option_type === self::CANONICAL_OPTION_TYPE)->values();
        $unexpected = $activePrices->reject(fn (FeePrice $p) => in_array($p->option_type, [self::LEGACY_OPTION_TYPE, self::CANONICAL_OPTION_TYPE], true))->values();

        $result['legacy_found'] = $legacy->count();
        $result['canonical_found'] = $canonical->count();
        $result['unexpected_found'] = $unexpected->count();
        $result['legacy_rows'] = $legacy->map(fn (FeePrice $p) => [
            'id' => $p->id,
            'option_value' => (string) $p->option_value,
            'payment_period' => $p->payment_period,
        ])->all();

        if ($unexpected->isNotEmpty()) {
            $result['unexpected_option_types'] = $unexpected->map(fn (FeePrice $p) => [
                'id' => $p->id,
                'option_type' => $p->option_type,
            ])->all();

            return $result;
        }

        if ($legacy->isEmpty()) {
            return $result;
        }

        // Blank option_value on a legacy row can never be safely normalized
        // — treated as an unexpected/unsafe state, same fail-closed path.
        $blank = $legacy->filter(fn (FeePrice $p) => blank($p->option_value));
        if ($blank->isNotEmpty()) {
            $result['unexpected_option_types'] = $blank->map(fn (FeePrice $p) => [
                'id' => $p->id,
                'option_type' => $p->option_type,
            ])->all();

            return $result;
        }

        // Transport pricing identity (per InvoiceCalculationService's own
        // dimensional matching) is (option_value, payment_period) for this
        // Fee+year — option_type is deliberately excluded from the identity
        // key here because it is exactly the field being normalized.
        $identity = fn (FeePrice $p) => $p->option_value.'|'.($p->payment_period ?? '');

        $legacyByIdentity = $legacy->groupBy($identity);
        $canonicalByIdentity = $canonical->groupBy($identity);

        $duplicateLegacyIdentities = $legacyByIdentity->filter(fn ($group) => $group->count() > 1);
        $conflictingIdentities = $legacyByIdentity->keys()->filter(fn ($key) => $canonicalByIdentity->has($key));

        if ($duplicateLegacyIdentities->isNotEmpty() || $conflictingIdentities->isNotEmpty()) {
            $conflicts = [];
            foreach ($duplicateLegacyIdentities as $key => $group) {
                $conflicts[] = [
                    'identity' => $key,
                    'legacy_ids' => $group->pluck('id')->all(),
                    'canonical_ids' => ($canonicalByIdentity->get($key) ?? collect())->pluck('id')->all(),
                ];
            }
            foreach ($conflictingIdentities as $key) {
                if (($duplicateLegacyIdentities->get($key)) !== null) {
                    continue; // already reported above
                }
                $conflicts[] = [
                    'identity' => $key,
                    'legacy_ids' => $legacyByIdentity->get($key)->pluck('id')->all(),
                    'canonical_ids' => $canonicalByIdentity->get($key)->pluck('id')->all(),
                ];
            }
            $result['conflicts'] = $conflicts;

            return $result;
        }

        $result['eligible'] = $legacy->count();

        if (! $apply) {
            return $result;
        }

        $legacyIds = $legacy->pluck('id')->all();

        DB::transaction(function () use ($legacyIds): void {
            FeePrice::query()
                ->whereIn('id', $legacyIds)
                ->update(['option_type' => self::CANONICAL_OPTION_TYPE]);
        });

        $result['normalized'] = count($legacyIds);
        $result['applied'] = true;

        return $result;
    }

    /**
     * Category-first resolution — never by name_ru — identical convention
     * already established for Uniform
     * (SchoolPriceListImportService::resolveOperationalUniformFee(),
     * UniformProductCatalogSyncService::resolveOperationalUniformFee()):
     * an operator's ad-hoc test fixture (is_test_data=true) must never be
     * selected as "the" real Transport service, and more than one
     * eligible candidate is a fail-closed condition, never an automatic
     * guess.
     */
    private function resolveOperationalTransportFee(): ?Fee
    {
        $candidates = Fee::where('category', Fee::CATEGORY_TRANSPORT)
            ->where('is_test_data', false)
            ->get();

        if ($candidates->count() > 1) {
            throw new RuntimeException('Найдено несколько операционных услуг категории «transport» — автоматическая нормализация невозможна, требуется ручное вмешательство.');
        }

        return $candidates->first();
    }
}
