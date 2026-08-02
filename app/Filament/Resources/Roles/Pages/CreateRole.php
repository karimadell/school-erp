<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Support\LeadershipAuthorization;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function beforeCreate(): void
    {
        LeadershipAuthorization::authorizeRoleName(auth()->user(), $this->data['name']);
        LeadershipAuthorization::authorizeProtectedPermissions(auth()->user(), $this->data['permissions'] ?? []);
    }
}
