<?php

namespace App\Policies;

use App\Models\FeePrice;
use App\Models\User;

class FeePricePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage fee prices');
    }

    public function view(User $user, FeePrice $feePrice): bool
    {
        return $user->can('manage fee prices');
    }

    public function create(User $user): bool
    {
        return $user->can('manage fee prices');
    }

    public function update(User $user, FeePrice $feePrice): bool
    {
        // P1 accounting integrity: FeePrice rows are append-only history —
        // a new version is created through the tariff workflow instead of
        // editing an existing price in place.
        return false;
    }

    public function delete(User $user, FeePrice $feePrice): bool
    {
        return false;
    }
}
