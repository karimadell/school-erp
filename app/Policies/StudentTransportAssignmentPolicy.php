<?php

namespace App\Policies;

use App\Models\StudentTransportAssignment;
use App\Models\User;
use App\Support\TransportPermissions;

class StudentTransportAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(TransportPermissions::VIEW);
    }

    public function view(User $user, StudentTransportAssignment $assignment): bool
    {
        return $user->can(TransportPermissions::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(TransportPermissions::MANAGE_ASSIGNMENTS);
    }

    public function update(User $user, StudentTransportAssignment $assignment): bool
    {
        return $user->can(TransportPermissions::MANAGE_ASSIGNMENTS);
    }

    public function delete(User $user, StudentTransportAssignment $assignment): bool
    {
        return false;
    }
}
