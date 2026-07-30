<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view invoices') || $user->can('manage invoices');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can('view invoices') || $user->can('manage invoices');
    }

    public function create(User $user): bool
    {
        return $user->can('manage invoices');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can('manage invoices');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        // Deleting a financial record is explicitly reserved for full
        // invoice management — read-only roles never reach this.
        return $user->can('manage invoices');
    }
}
