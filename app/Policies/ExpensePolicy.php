<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage expenses');
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->can('manage expenses');
    }

    public function create(User $user): bool
    {
        return $user->can('manage expenses');
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->can('manage expenses');
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->can('manage expenses');
    }
}
