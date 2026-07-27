<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * System configuration — Super Admin only. Explicitly registered in
 * AuthServiceProvider since Role is a Spatie package model, outside the
 * App\Models namespace Laravel's policy auto-discovery scans.
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
        return $user->can('manage roles');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('manage roles');
    }
}
