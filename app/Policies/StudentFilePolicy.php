<?php

namespace App\Policies;

use App\Models\User;
use App\Models\StudentFile;

class StudentFilePolicy
{
    /**
     * View files
     */
    public function view(User $user): bool
    {
        return $user->can('view invoices');
    }

    /**
     * Upload file
     */
    public function create(User $user): bool
    {
        return $user->can('manage invoices');
    }

    /**
     * Delete file
     */
    public function delete(User $user, StudentFile $file): bool
    {
        // Admin يقدر يحذف أي ملف
        if ($user->hasRole('admin')) {
            return true;
        }

        // غير كده لازم صلاحية إدارة الفواتير
        return $user->can('manage invoices');
    }
}