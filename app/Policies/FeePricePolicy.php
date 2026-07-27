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
        return $user->can('manage fee prices');
    }

    public function delete(User $user, FeePrice $feePrice): bool
    {
        return $user->can('manage fee prices');
    }
}
