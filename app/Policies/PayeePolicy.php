<?php

namespace App\Policies;

use App\Models\Payee;
use App\Models\User;

// Gated on 'manage expenses' — payee administration is part of the same
// operational surface as Expense itself, not a separately-permissioned duty.
class PayeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage expenses');
    }

    public function view(User $user, Payee $payee): bool
    {
        return $user->can('manage expenses');
    }

    public function create(User $user): bool
    {
        return $user->can('manage expenses');
    }

    public function update(User $user, Payee $payee): bool
    {
        return $user->can('manage expenses');
    }

    public function delete(User $user, Payee $payee): bool
    {
        return $user->can('manage expenses');
    }
}
