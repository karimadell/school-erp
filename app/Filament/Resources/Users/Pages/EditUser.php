<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected ?array $rolesAndPermissionsBeforeSave = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Batch 7: role/permission assignment is a BelongsToMany sync, which
     * never fires the User model's own Eloquent events — the AuditObserver
     * already registered on User (see AppServiceProvider) cannot see it.
     * Capturing and logging the change explicitly here is the only way a
     * sensitive access-control action like this gets an audit trail.
     */
    protected function beforeSave(): void
    {
        $this->rolesAndPermissionsBeforeSave = $this->currentRolesAndPermissions();
    }

    protected function afterSave(): void
    {
        $before = $this->rolesAndPermissionsBeforeSave;
        $after = $this->currentRolesAndPermissions();

        if ($before === $after) {
            return;
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'permissions_updated',
            'model' => 'User',
            'model_id' => $this->record->id,
            'old_values' => $before,
            'new_values' => $after,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    protected function currentRolesAndPermissions(): array
    {
        return [
            'roles' => $this->record->roles()->pluck('name')->sort()->values()->all(),
            'permissions' => $this->record->permissions()->pluck('name')->sort()->values()->all(),
        ];
    }
}
