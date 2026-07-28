<?php

namespace App\Policies;

use App\Models\Curriculum;
use App\Models\User;

/**
 * Item 8 / C1: CRUD gated by 'manage curriculum', admin-only per approved
 * decision for this initial implementation.
 */
class CurriculumPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage curriculum');
    }

    public function view(User $user, Curriculum $curriculum): bool
    {
        return $user->can('manage curriculum');
    }

    public function create(User $user): bool
    {
        return $user->can('manage curriculum');
    }

    public function update(User $user, Curriculum $curriculum): bool
    {
        return $user->can('manage curriculum');
    }

    public function delete(User $user, Curriculum $curriculum): bool
    {
        return $user->can('manage curriculum');
    }
}
