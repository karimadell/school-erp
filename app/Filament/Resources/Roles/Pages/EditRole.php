<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Support\LeadershipAuthorization;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function beforeSave(): void
    {
        LeadershipAuthorization::authorizeRoleName(auth()->user(), $this->data['name']);
        LeadershipAuthorization::authorizeProtectedPermissions(auth()->user(), $this->data['permissions'] ?? []);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
