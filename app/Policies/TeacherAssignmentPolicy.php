<?php

namespace App\Policies;

use App\Models\TeacherAssignment;
use App\Models\User;

class TeacherAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage teachers');
    }

    public function view(User $user, TeacherAssignment $teacherAssignment): bool
    {
        return $user->can('manage teachers');
    }

    public function create(User $user): bool
    {
        return $user->can('manage teachers');
    }

    public function update(User $user, TeacherAssignment $teacherAssignment): bool
    {
        return $user->can('manage teachers');
    }

    public function delete(User $user, TeacherAssignment $teacherAssignment): bool
    {
        return $user->can('manage teachers');
    }
}
