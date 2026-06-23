<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Tables;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('name')
                    ->label('Имя')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email'),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Роль')
                    ->badge(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime(),

            ]);
    }
}