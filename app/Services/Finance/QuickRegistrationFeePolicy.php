<?php

namespace App\Services\Finance;

use App\Models\Fee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class QuickRegistrationFeePolicy
{
    public function __construct(private NewSaleFeePolicy $newSaleFeePolicy) {}

    public function apply(Builder $query): Builder
    {
        return $this->newSaleFeePolicy->apply($query)
            ->where('category', '!=', Fee::CATEGORY_ACTIVITY);
    }

    public function assertEligible(Fee $fee, string $field = 'fees'): void
    {
        $this->newSaleFeePolicy->assertEligible($fee, $field);

        if ($fee->category === Fee::CATEGORY_ACTIVITY) {
            throw ValidationException::withMessages([
                $field => 'Мероприятия и экскурсии оформляются после регистрации ученика.',
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

        $this->newSaleFeePolicy->assertEligibleIds($ids, $field);

        /** @var Collection<int, Fee> $fees */
        $fees = Fee::query()->whereIn('id', $ids)->get();
        foreach ($fees as $fee) {
            $this->assertEligible($fee, $field);
        }
    }
}
