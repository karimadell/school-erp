<?php

namespace App\Policies;

use App\Models\RevenueEntry;
use App\Models\User;

class RevenueEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage revenues');
    }

    public function view(User $user, RevenueEntry $entry): bool
    {
        return $user->can('manage revenues');
    }

    public function create(User $user): bool
    {
        return $user->can('manage revenues');
    }

    // Only a draft may be edited; posted/reversed are immutable regardless
    // of permission — the model's own saving() guard enforces this too
    // (defense in depth).
    public function update(User $user, RevenueEntry $entry): bool
    {
        return $user->can('manage revenues') && $entry->status === RevenueEntry::STATUS_DRAFT;
    }

    // A draft has no ledger impact yet and may be deleted; posted/reversed
    // are permanent financial history.
    public function delete(User $user, RevenueEntry $entry): bool
    {
        return $user->can('manage revenues') && $entry->status === RevenueEntry::STATUS_DRAFT;
    }

    public function post(User $user, RevenueEntry $entry): bool
    {
        return $user->can('post revenues') && $entry->status === RevenueEntry::STATUS_DRAFT;
    }

    public function reverse(User $user, RevenueEntry $entry): bool
    {
        return $user->can('reverse revenues') && $entry->status === RevenueEntry::STATUS_POSTED;
    }
}
