<?php

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;

// Gated on 'manage expenses' — category administration is part of the same
// operational surface as Expense itself, not a separately-permissioned duty.
class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage expenses');
    }

    public function view(User $user, ExpenseCategory $category): bool
    {
        return $user->can('manage expenses');
    }

    public function create(User $user): bool
    {
        return $user->can('manage expenses');
    }

    public function update(User $user, ExpenseCategory $category): bool
    {
        return $user->can('manage expenses');
    }

    public function delete(User $user, ExpenseCategory $category): bool
    {
        return $user->can('manage expenses');
    }
}
