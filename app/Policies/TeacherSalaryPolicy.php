<?php

namespace App\Policies;

use App\Models\TeacherSalary;
use App\Models\User;

class TeacherSalaryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view payroll');
    }

    public function view(User $user, TeacherSalary $payroll): bool
    {
        return $user->can('view payroll');
    }

    public function create(User $user): bool
    {
        return $user->can('manage payroll');
    }

    public function update(User $user, TeacherSalary $payroll): bool
    {
        return $user->can('manage payroll') && $payroll->status === TeacherSalary::STATUS_DRAFT;
    }

    public function delete(User $user, TeacherSalary $payroll): bool
    {
        return $user->can('manage payroll') && $payroll->status === TeacherSalary::STATUS_DRAFT;
    }
}
