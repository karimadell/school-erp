<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms;
use Filament\Schemas\Schema;

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
                ->required(),

            Forms\Components\Select::make('roles')
                ->label('Роль')
                ->relationship('roles','name')
                ->multiple()
                ->preload(),

            Forms\Components\CheckboxList::make('permissions')
                ->label('Разрешения')
                ->relationship('permissions','name')
                ->columns(3)

        ]);
    }
}