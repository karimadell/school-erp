<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Explicitly registered because Role is a Spatie package model. Ordinary
 * role management follows permissions; super-admin itself is protected.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage roles');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('manage roles');
    }

    public function create(User $user): bool
    {
        return $user->can('manage roles');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('manage roles')
            && ($role->name !== 'super-admin' || $user->isSuperAdmin());
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('manage roles') && $role->name !== 'super-admin';
    }
}
