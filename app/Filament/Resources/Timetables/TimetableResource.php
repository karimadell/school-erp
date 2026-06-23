<?php

namespace App\Filament\Resources;

use App\Models\Timetable;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use App\Filament\Resources\TimetableResource\Pages;

class TimetableResource extends Resource
{
    protected static ?string $model = Timetable::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static \UnitEnum|string|null $navigationGroup = 'Академия';

    protected static ?string $modelLabel = 'Урок';

    protected static ?string $pluralModelLabel = 'Расписание';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([

            Forms\Components\Select::make('class_id')
                ->label('Класс')
                ->relationship('class', 'name_ru')
                ->searchable()
                ->required(),

            Forms\Components\Select::make('subject_id')
                ->label('Предмет')
                ->relationship('subject', 'name_ru')
                ->searchable()
                ->required(),

            Forms\Components\Select::make('teacher_id')
                ->label('Учитель')
                ->relationship('teacher', 'name_ru')
                ->searchable()
                ->required(),

            Forms\Components\Select::make('day_id')
                ->label('День')
                ->relationship('day', 'name_ru')
                ->required(),

            Forms\Components\Select::make('period_id')
                ->label('Урок')
                ->relationship('period', 'name_ru')
                ->required(),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('class.name_ru')
                    ->label('Класс')
                    ->sortable(),

                Tables\Columns\TextColumn::make('day.name_ru')
                    ->label('День'),

                Tables\Columns\TextColumn::make('period.name_ru')
                    ->label('Урок'),

                Tables\Columns\TextColumn::make('subject.name_ru')
                    ->label('Предмет'),

                Tables\Columns\TextColumn::make('teacher.name_ru')
                    ->label('Учитель'),

            ])
            ->defaultSort('day_id')
            ->filters([

                Tables\Filters\SelectFilter::make('class')
                    ->relationship('class', 'name_ru')
                    ->label('Класс'),

            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTimetables::route('/'),
            'create' => Pages\CreateTimetable::route('/create'),
            'edit' => Pages\EditTimetable::route('/{record}/edit'),
        ];
    }
}