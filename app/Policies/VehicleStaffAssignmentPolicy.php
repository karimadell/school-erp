<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleStaffAssignment;
use App\Support\TransportPermissions;

class VehicleStaffAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(TransportPermissions::VIEW);
    }

    public function view(User $user, VehicleStaffAssignment $assignment): bool
    {
        return $user->can(TransportPermissions::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(TransportPermissions::MANAGE_ASSIGNMENTS);
    }

    public function update(User $user, VehicleStaffAssignment $assignment): bool
    {
        return $user->can(TransportPermissions::MANAGE_ASSIGNMENTS);
    }

    public function delete(User $user, VehicleStaffAssignment $assignment): bool
    {
        return false;
    }
}
