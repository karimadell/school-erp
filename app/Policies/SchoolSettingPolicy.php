<?php

namespace App\Policies;

use App\Models\SchoolSetting;
use App\Models\User;

class SchoolSettingPolicy
{
    public function update(User $user, SchoolSetting $setting): bool
    {
        return $user->is_active && ($user->isSuperAdmin() || $user->hasRole('admin'));
    }
}
