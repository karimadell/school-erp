<?php

namespace App\Policies;

use App\Models\StudentSubjectEnrollment;
use App\Models\User;

/**
 * Batch 11 / C2: CRUD gated by the existing 'manage curriculum'
 * permission — reused per approved decision rather than introducing a
 * new permission. This is a curriculum-office concern ("who is actually
 * enrolled in this elective"), same authority boundary as CurriculumPolicy.
 */
class StudentSubjectEnrollmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage curriculum');
    }

    public function view(User $user, StudentSubjectEnrollment $studentSubjectEnrollment): bool
    {
        return $user->can('manage curriculum');
    }

    public function create(User $user): bool
    {
        return $user->can('manage curriculum');
    }

    public function update(User $user, StudentSubjectEnrollment $studentSubjectEnrollment): bool
    {
        return $user->can('manage curriculum');
    }

    public function delete(User $user, StudentSubjectEnrollment $studentSubjectEnrollment): bool
    {
        return $user->can('manage curriculum');
    }
}
