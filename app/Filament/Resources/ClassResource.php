<?php

namespace App\Filament\Resources;

use App\Models\SchoolClass;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use App\Filament\Resources\ClassResource\Pages;

class ClassResource extends Resource
{
    protected static ?string $model = SchoolClass::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office';

    protected static \UnitEnum|string|null $navigationGroup = 'Академия';

    protected static ?string $modelLabel = 'Класс';

    protected static ?string $pluralModelLabel = 'Классы';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([

            Forms\Components\TextInput::make('code')
                ->label('Код'),

            Forms\Components\TextInput::make('name_ru')
                ->label('Название')
                ->required(),

            Forms\Components\TextInput::make('capacity')
                ->label('Вместимость')
                ->numeric(),

            Forms\Components\Toggle::make('is_active')
                ->label('Активный')
                ->default(true),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('name_ru')
                    ->label('Название'),

                Tables\Columns\TextColumn::make('capacity')
                    ->label('Вместимость'),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),

            ]);
    }

    public static function getPages(): array
    {
        return [

            'index' => Pages\ListClasses::route('/'),

            'create' => Pages\CreateClass::route('/create'),

            'edit' => Pages\EditClass::route('/{record}/edit'),

            // صفحة الجدول الدراسي
            'timetable' => Pages\TimetableGrid::route('/{record}/timetable'),

        ];
    }
}