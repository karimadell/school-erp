<?php

namespace App\Filament\Resources\Permissions\Schemas;

use Filament\Forms;
use Filament\Schemas\Schema;

class PermissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Forms\Components\TextInput::make('name')
                ->label('Название разрешения')
                ->required(),

        ]);
    }
}