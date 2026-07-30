<?php

namespace App\Policies;

use App\Models\AcademicYear;
use App\Models\User;

/**
 * Item 2: AcademicYearResource previously had no policy at all — any user
 * reaching /admin could create/edit/delete years and flip is_active with
 * no gate. CRUD (including the is_active toggle) is gated by the existing
 * 'manage academic years' permission. unlock() is a separate, dedicated
 * ability gated by 'unlock historical academic year' — kept independent on
 * purpose (approved decision), not folded into the CRUD permission.
 */
class AcademicYearPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage academic years');
    }

    public function view(User $user, AcademicYear $academicYear): bool
    {
        return $user->can('manage academic years');
    }

    public function create(User $user): bool
    {
        return $user->can('manage academic years');
    }

    public function update(User $user, AcademicYear $academicYear): bool
    {
        return $user->can('manage academic years');
    }

    public function delete(User $user, AcademicYear $academicYear): bool
    {
        return $user->can('manage academic years');
    }

    public function unlock(User $user, AcademicYear $academicYear): bool
    {
        return $user->can('unlock historical academic year');
    }
}
