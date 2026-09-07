<?php

namespace App\Policies;

use App\Models\TransportRoute;
use App\Models\User;
use App\Support\TransportPermissions;

class TransportRoutePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(TransportPermissions::VIEW);
    }

    public function view(User $user, TransportRoute $route): bool
    {
        return $user->can(TransportPermissions::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(TransportPermissions::MANAGE_ROUTES);
    }

    public function update(User $user, TransportRoute $route): bool
    {
        return $user->can(TransportPermissions::MANAGE_ROUTES);
    }

    public function delete(User $user, TransportRoute $route): bool
    {
        return false;
    }
}
