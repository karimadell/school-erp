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

    // 'manage expenses' keeps its pre-existing create/edit-draft/view
    // compatibility grant; a paid or voided expense is never editable
    // regardless of permission — the model's own saving() guard enforces
    // this as well (defense in depth).
    public function update(User $user, Expense $expense): bool
    {
        return $user->can('manage expenses')
            && in_array($expense->status, [Expense::STATUS_DRAFT, Expense::STATUS_APPROVED], true);
    }

    // No destructive delete for financially significant Expense records —
    // not even drafts, which are still part of the approval audit trail.
    public function delete(User $user, Expense $expense): bool
    {
        return false;
    }

    public function approve(User $user, Expense $expense): bool
    {
        return $user->can('approve expenses') && $expense->status === Expense::STATUS_DRAFT;
    }

    public function pay(User $user, Expense $expense): bool
    {
        return $user->can('post expenses') && $expense->status === Expense::STATUS_APPROVED;
    }

    public function void(User $user, Expense $expense): bool
    {
        return $user->can('void expenses')
            && in_array($expense->status, [Expense::STATUS_DRAFT, Expense::STATUS_APPROVED], true);
    }
}
