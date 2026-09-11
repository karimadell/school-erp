<?php

namespace App\Policies;

use App\Models\RevenueCategory;
use App\Models\User;

// Gated on 'manage revenues' — category administration is part of the same
// operational surface as RevenueEntry itself, not a separately-permissioned
// duty (matches ExpenseCategoryPolicy's precedent for Expenses V1).
class RevenueCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage revenues');
    }

    public function view(User $user, RevenueCategory $category): bool
    {
        return $user->can('manage revenues');
    }

    public function create(User $user): bool
    {
        return $user->can('manage revenues');
    }

    public function update(User $user, RevenueCategory $category): bool
    {
        return $user->can('manage revenues');
    }

    public function delete(User $user, RevenueCategory $category): bool
    {
        return $user->can('manage revenues');
    }
}
