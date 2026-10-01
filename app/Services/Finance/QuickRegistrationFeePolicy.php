<?php

namespace App\Services\Finance;

use App\Models\Fee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quick Registration's Fee catalog — a thin delegate to the shared
 * StudentServiceEligibilityPolicy year-setup context (canonical tuition and
 * registration allowed; activities, legacy tuition, inactive and test Fees
 * excluded). Kept as its own class so Quick Registration's callers stay
 * unchanged.
 */
class QuickRegistrationFeePolicy
{
    public function __construct(private StudentServiceEligibilityPolicy $eligibility) {}

    public function apply(Builder $query): Builder
    {
        return $this->eligibility->applyYearSetup($query);
    }

    public function assertEligible(Fee $fee, string $field = 'fees'): void
    {
        $this->eligibility->assertEligible($fee, StudentServiceEligibilityPolicy::CONTEXT_YEAR_SETUP, $field);
    }

    /** @param iterable<int, int|string> $ids */
    public function assertEligibleIds(iterable $ids, string $field = 'fees'): void
    {
        $this->eligibility->assertEligibleIds($ids, StudentServiceEligibilityPolicy::CONTEXT_YEAR_SETUP, $field);
    }
}
