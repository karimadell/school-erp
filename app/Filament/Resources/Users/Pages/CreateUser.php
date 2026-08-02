<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Support\LeadershipAuthorization;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function beforeCreate(): void
    {
        LeadershipAuthorization::authorizeRoleAssignment(auth()->user(), $this->data['roles'] ?? []);
        LeadershipAuthorization::authorizeProtectedPermissions(auth()->user(), $this->data['permissions'] ?? []);
    }

    /**
     * Batch 7: the initial role/permission assignment on a new user is a
     * BelongsToMany sync too — logged explicitly for the same reason as
     * EditUser (see its docblock).
     */
    protected function afterCreate(): void
    {
        $roles = $this->record->roles()->pluck('name')->sort()->values()->all();
        $permissions = $this->record->permissions()->pluck('name')->sort()->values()->all();

        if (empty($roles) && empty($permissions)) {
            return;
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'permissions_assigned',
            'model' => 'User',
            'model_id' => $this->record->id,
            'old_values' => null,
            'new_values' => ['roles' => $roles, 'permissions' => $permissions],
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
