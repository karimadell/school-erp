<?php

namespace App\Filament\Resources;

use App\Models\Bus;
use Filament\Forms;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use App\Filament\Resources\BusResource\Pages;

class BusResource extends Resource
{
    protected static ?string $model = Bus::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-truck';

    protected static \UnitEnum|string|null $navigationGroup = 'Transport';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->label('Bus Name')
                ->required(),

            Forms\Components\TextInput::make('plate_number')
                ->label('Plate Number')
                ->required(),

            Forms\Components\TextInput::make('capacity')
                ->label('Capacity')
                ->numeric()
                ->required(),

            Forms\Components\Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Bus Name'),

                Tables\Columns\TextColumn::make('plate_number')->label('Plate Number'),

                Tables\Columns\TextColumn::make('capacity')->label('Capacity'),

                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
{
    return [];
}
}