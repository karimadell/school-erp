<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * System configuration — Super Admin only. Explicitly registered in
 * AuthServiceProvider since Permission is a Spatie package model.
 */
class PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage permissions');
    }

    public function view(User $user, Permission $permission): bool
    {
        return $user->can('manage permissions');
    }

    public function create(User $user): bool
    {
        return $user->can('manage permissions');
    }

    public function update(User $user, Permission $permission): bool
    {
        return $user->can('manage permissions');
    }

    public function delete(User $user, Permission $permission): bool
    {
        return $user->can('manage permissions');
    }
}
