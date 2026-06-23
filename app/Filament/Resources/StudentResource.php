<?php

namespace App\Filament\Resources;

use App\Models\Student;
use Filament\Forms;
use Filament\Tables;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use App\Filament\Resources\StudentResource\Pages;

class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    protected static \UnitEnum|string|null $navigationGroup = 'Академия';

    protected static ?string $navigationLabel = 'Студенты';

    protected static ?string $modelLabel = 'Студент';

    protected static ?string $pluralModelLabel = 'Студенты';


    public static function form(Schema $schema): Schema
    {
        return $schema->components([

            Forms\Components\Select::make('class_id')
                ->label('Класс')
                ->relationship('class', 'name_ru')
                ->searchable()
                ->preload()
                ->required(),

            Forms\Components\TextInput::make('first_name')
                ->label('Имя')
                ->required(),

            Forms\Components\TextInput::make('last_name')
                ->label('Фамилия')
                ->required(),

            Forms\Components\TextInput::make('phone')
                ->label('Телефон'),

            Forms\Components\TextInput::make('email')
                ->label('Email')
                ->email(),

            Forms\Components\DatePicker::make('birth_date')
                ->label('Дата рождения'),

            Forms\Components\Select::make('gender')
                ->label('Пол')
                ->options([
                    'male' => 'Мужской',
                    'female' => 'Женский',
                ]),

            Forms\Components\Toggle::make('is_active')
                ->label('Активный')
                ->default(true),

        ]);
    }


    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('first_name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_name')
                    ->label('Фамилия')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('class.name_ru')
                    ->label('Класс')
                    ->sortable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Телефон'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активный')
                    ->boolean(),

            ])
            ->defaultSort('last_name');
    }


    public static function getPages(): array
    {
        return [

            'index' => Pages\ListStudents::route('/'),

            'create' => Pages\CreateStudent::route('/create'),

            'edit' => Pages\EditStudent::route('/{record}/edit'),

        ];
    }
}