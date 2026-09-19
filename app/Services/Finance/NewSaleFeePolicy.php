<?php

namespace App\Services\Finance;

use App\Models\Fee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class NewSaleFeePolicy
{
    /** @return array<int, string> */
    public function legacyTuitionCategories(): array
    {
        return [
            Fee::CATEGORY_TUITION_REGULAR,
            Fee::CATEGORY_TUITION_FAMILY,
            Fee::CATEGORY_TUITION_EXTERNAL,
        ];
    }

    public function apply(Builder $query): Builder
    {
        return $query->whereNotIn('category', $this->legacyTuitionCategories());
    }

    public function assertEligible(Fee $fee, string $field = 'fees'): void
    {
        if (in_array($fee->category, $this->legacyTuitionCategories(), true)) {
            throw ValidationException::withMessages([
                $field => 'Для новых начислений используется единая услуга «Обучение»; устаревшая отдельная услуга недоступна.',
            ]);
        }
    }

    /** @param iterable<int, int|string> $ids */
    public function assertEligibleIds(iterable $ids, string $field = 'fees'): void
    {
        $ids = collect($ids)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        /** @var Collection<int, Fee> $fees */
        $fees = Fee::query()->whereIn('id', $ids)->get();
        foreach ($fees as $fee) {
            $this->assertEligible($fee, $field);
        }
    }
}
