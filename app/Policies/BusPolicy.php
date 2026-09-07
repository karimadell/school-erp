<?php

namespace App\Policies;

use App\Models\Bus;
use App\Models\User;
use App\Support\TransportPermissions;

class BusPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(TransportPermissions::VIEW);
    }

    public function view(User $user, Bus $bus): bool
    {
        return $user->can(TransportPermissions::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(TransportPermissions::MANAGE_VEHICLES);
    }

    public function update(User $user, Bus $bus): bool
    {
        return $user->can(TransportPermissions::MANAGE_VEHICLES);
    }

    public function delete(User $user, Bus $bus): bool
    {
        return false;
    }
}
