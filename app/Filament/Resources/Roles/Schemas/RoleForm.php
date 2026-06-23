<?php

namespace App\Filament\Resources\Roles\Schemas;

use Filament\Forms;
use Filament\Schemas\Schema;

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
                ->relationship('permissions','name')
                ->columns(3)

        ]);
    }
}