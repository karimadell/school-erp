<?php

namespace App\Policies;

use App\Models\Fee;
use App\Models\User;

class FeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage fees');
    }

    public function view(User $user, Fee $fee): bool
    {
        return $user->can('manage fees');
    }

    public function create(User $user): bool
    {
        return $user->can('manage fees');
    }

    public function update(User $user, Fee $fee): bool
    {
        return $user->can('manage fees');
    }

    public function delete(User $user, Fee $fee): bool
    {
        return $user->can('manage fees');
    }
}
