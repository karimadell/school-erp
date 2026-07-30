<?php

namespace App\Policies;

use App\Models\StudentServiceSubscription;
use App\Models\User;

/**
 * No Filament resource exists yet for this model (Batch 5 deliberately
 * shipped the data/service layer only) — this policy is forward-looking,
 * ready for whenever that resource is built.
 */
class StudentServiceSubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage student service subscriptions');
    }

    public function view(User $user, StudentServiceSubscription $subscription): bool
    {
        return $user->can('manage student service subscriptions');
    }

    public function create(User $user): bool
    {
        return $user->can('manage student service subscriptions');
    }

    public function update(User $user, StudentServiceSubscription $subscription): bool
    {
        return $user->can('manage student service subscriptions');
    }

    public function delete(User $user, StudentServiceSubscription $subscription): bool
    {
        return $user->can('manage student service subscriptions');
    }
}
