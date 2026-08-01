<?php

namespace App\Filament\Resources;

use App\Models\SchoolClass;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use App\Filament\Resources\ClassResource\Pages;
use App\Filament\Resources\ClassResource\RelationManagers\ClassTeachersRelationManager;

class ClassResource extends Resource
{
    protected static ?string $model = SchoolClass::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office';

    protected static \UnitEnum|string|null $navigationGroup = 'Учебный процесс';

    protected static ?int $navigationSort = 20;

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

            ])
            ->recordActions([

                // Batch 5 / Timetable Navigation (docs/TIMETABLE_ARCHITECTURE_DECISIONS.md):
                // the only discoverable link to the canonical TimetableGrid
                // page — it is a per-class sub-page and can never have its
                // own top-level navigation item. Visibility mirrors
                // TimetableGrid::canAccess()/mount() exactly, so this link
                // is never shown to a user who would immediately be
                // aborted for clicking it.
                Action::make('timetable')
                    ->label(__('timetable.view_schedule'))
                    ->icon('heroicon-o-calendar')
                    ->url(fn ($record) => static::getUrl('timetable', ['record' => $record]))
                    ->visible(fn () => auth()->user()?->hasAnyPermission(['view timetable', 'manage timetable']) ?? false),

            ]);
    }

    public static function getRelations(): array
    {
        return [
            ClassTeachersRelationManager::class,
        ];
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
