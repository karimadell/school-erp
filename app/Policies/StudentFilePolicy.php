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
        return $user->isActive() && $user->can('manage students');
    }

    /**
     * Upload file
     */
    public function create(User $user): bool
    {
        return $user->isActive() && $user->can('manage students');
    }

    /**
     * Delete file
     */
    public function delete(User $user, StudentFile $file): bool
    {
        return false;
    }
}
