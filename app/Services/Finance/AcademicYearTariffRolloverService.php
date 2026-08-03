<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\FeePrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcademicYearTariffRolloverService
{
    private const COPY_FIELDS = [
        'fee_id', 'amount', 'currency', 'grade_id', 'grade_group', 'payment_period',
        'option_type', 'option_value', 'size', 'item', 'notes', 'change_reason', 'is_active',
    ];

    private const DIMENSION_FIELDS = [
        'fee_id', 'grade_id', 'grade_group', 'payment_period', 'option_type', 'option_value', 'size', 'item',
    ];

    /** @return array{source_year:AcademicYear,target_year:AcademicYear,services_found:int,tariffs_found:int,existing_target:int,new_tariffs:int,skipped:int} */
    public function preview(int $sourceYearId, int $targetYearId): array
    {
        [$source, $target] = $this->years($sourceYearId, $targetYearId);
        $sourceTariffs = FeePrice::query()->where('academic_year_id', $source->id)->get();
        $targetTariffs = FeePrice::query()->where('academic_year_id', $target->id)->get();
        $skipped = $sourceTariffs->filter(fn (FeePrice $tariff) => $this->targetHasVariant($targetTariffs, $tariff))->count();

        return [
            'source_year' => $source,
            'target_year' => $target,
            'services_found' => $sourceTariffs->pluck('fee_id')->unique()->count(),
            'tariffs_found' => $sourceTariffs->count(),
            'existing_target' => $targetTariffs->count(),
            'new_tariffs' => $sourceTariffs->count() - $skipped,
            'skipped' => $skipped,
        ];
    }

    /** @return array{created:int,skipped:int} */
    public function copy(int $sourceYearId, int $targetYearId): array
    {
        return DB::transaction(function () use ($sourceYearId, $targetYearId): array {
            [$source, $target] = $this->years($sourceYearId, $targetYearId, true);
            $sourceTariffs = FeePrice::query()->where('academic_year_id', $source->id)->orderBy('id')->lockForUpdate()->get();
            $targetTariffs = FeePrice::query()->where('academic_year_id', $target->id)->lockForUpdate()->get();
            $created = 0;
            $skipped = 0;

            foreach ($sourceTariffs as $sourceTariff) {
                // A target variant entered manually always wins. This avoids overlaps
                // and never rewrites either the source or the target record.
                if ($this->targetHasVariant($targetTariffs, $sourceTariff)) {
                    $skipped++;
                    continue;
                }

                $attributes = $sourceTariff->only(self::COPY_FIELDS);
                $attributes['academic_year_id'] = $target->id;
                $attributes['start_date'] = $target->start_date->toDateString();
                $attributes['end_date'] = $target->end_date->toDateString();
                $copy = FeePrice::create($attributes);
                $targetTariffs->push($copy);
                $created++;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /** @return array{0:AcademicYear,1:AcademicYear} */
    private function years(int $sourceYearId, int $targetYearId, bool $lock = false): array
    {
        if ($sourceYearId === $targetYearId) {
            throw ValidationException::withMessages(['target_academic_year_id' => 'Выберите другой учебный год для копирования.']);
        }

        $query = AcademicYear::query()->whereIn('id', [$sourceYearId, $targetYearId]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $years = $query->get()->keyBy('id');
        if (! $years->has($sourceYearId) || ! $years->has($targetYearId)) {
            throw ValidationException::withMessages(['academic_year' => 'Выбранный учебный год не найден.']);
        }

        return [$years->get($sourceYearId), $years->get($targetYearId)];
    }

    private function targetHasVariant($targetTariffs, FeePrice $source): bool
    {
        return $targetTariffs->contains(function (FeePrice $target) use ($source): bool {
            foreach (self::DIMENSION_FIELDS as $field) {
                if ((string) ($target->{$field} ?? '') !== (string) ($source->{$field} ?? '')) {
                    return false;
                }
            }

            return true;
        });
    }
}
