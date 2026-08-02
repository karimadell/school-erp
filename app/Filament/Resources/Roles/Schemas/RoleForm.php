<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Support\LeadershipAuthorization;
use Filament\Forms;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Forms\Components\TextInput::make('name')
                ->label('Название роли')
                ->required(),

            Forms\Components\CheckboxList::make('permissions')
                ->label('Разрешения')
                ->relationship(
                    'permissions',
                    'name',
                    modifyQueryUsing: fn (Builder $query) => auth()->user()?->isSuperAdmin()
                        ? $query
                        : $query->whereNotIn('name', LeadershipAuthorization::protectedPermissionNames()),
                )
                ->columns(3)

        ]);
    }
}
