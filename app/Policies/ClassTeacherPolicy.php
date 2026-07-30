<?php

namespace App\Policies;

use App\Models\ClassTeacher;
use App\Models\User;

/**
 * Batch 11 / C4: CRUD gated by the existing 'manage classes' permission
 * — reused per approved decision rather than introducing a new
 * permission. This governs the ClassTeacher model itself (and, via
 * Filament's relation-manager authorization, the ClassTeachersRelation
 * Manager's actions on ClassResource) — not a SchoolClassPolicy, which
 * remains explicitly out of scope for this item.
 */
class ClassTeacherPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage classes');
    }

    public function view(User $user, ClassTeacher $classTeacher): bool
    {
        return $user->can('manage classes');
    }

    public function create(User $user): bool
    {
        return $user->can('manage classes');
    }

    public function update(User $user, ClassTeacher $classTeacher): bool
    {
        return $user->can('manage classes');
    }

    public function delete(User $user, ClassTeacher $classTeacher): bool
    {
        return $user->can('manage classes');
    }
}
