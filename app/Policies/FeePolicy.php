<?php

namespace App\Policies;

use App\Models\Fee;
use App\Models\User;

class FeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage fees');
    }

    public function view(User $user, Fee $fee): bool
    {
        return $user->can('manage fees');
    }

    public function create(User $user): bool
    {
        return $user->can('manage fees');
    }

    public function update(User $user, Fee $fee): bool
    {
        return $user->can('manage fees');
    }

    public function delete(User $user, Fee $fee): bool
    {
        // P1 accounting integrity: Fees may carry InvoiceItem/FeePrice
        // financial history. Physical deletion is never authorized, even
        // for privileged Finance roles — retire a Fee via is_active=false
        // in the canonical service catalog instead.
        return false;
    }
}
