<?php

namespace App\Policies;

use App\Models\User;

/**
 * User administration is permission-based. Protected super-admin accounts
 * require a super-admin actor, and the final active account cannot be deleted.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage users');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('manage users') && (! $model->isSuperAdmin() || $user->isSuperAdmin());
    }

    public function create(User $user): bool
    {
        return $user->can('manage users');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('manage users') && (! $model->isSuperAdmin() || $user->isSuperAdmin());
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can('manage users')
            && (! $model->isSuperAdmin() || $user->isSuperAdmin())
            && ! $model->isLastActiveSuperAdmin();
    }
}
