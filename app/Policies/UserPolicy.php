<?php

namespace App\Policies;

use App\Models\User;

/**
 * System configuration — reserved for Super Admin ('admin' role) only, per
 * the approved Batch 6 role mapping. School Admin has full operational
 * access but explicitly excludes user/role/permission management.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage users');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('manage users');
    }

    public function create(User $user): bool
    {
        return $user->can('manage users');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('manage users');
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can('manage users');
    }
}
