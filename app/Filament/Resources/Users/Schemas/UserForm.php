<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Support\LeadershipAuthorization;
use Filament\Forms;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Forms\Components\TextInput::make('name')
                ->label('Имя')
                ->required(),

            Forms\Components\TextInput::make('email')
                ->label('Email')
                ->email()
                ->required(),

            Forms\Components\TextInput::make('password')
                ->label('Пароль')
                ->password()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(fn (string $operation): ?string => $operation === 'edit'
                    ? 'Оставьте пустым, чтобы не менять пароль.'
                    : null),

            Forms\Components\Select::make('roles')
                ->label('Роль')
                ->relationship(
                    'roles',
                    'name',
                    modifyQueryUsing: fn (Builder $query) => auth()->user()?->isSuperAdmin()
                        ? $query
                        : $query
                            ->where('name', '!=', 'super-admin')
                            ->whereDoesntHave('permissions', fn (Builder $permissions) => $permissions
                                ->whereIn('name', LeadershipAuthorization::protectedPermissionNames())),
                )
                ->multiple()
                ->preload(),

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
