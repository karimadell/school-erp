<?php

namespace App\Filament\Resources;

use App\Models\Teacher;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use App\Filament\Resources\TeacherResource\Pages;

class TeacherResource extends Resource
{
    protected static ?string $model = Teacher::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    protected static \UnitEnum|string|null $navigationGroup = 'Академия';

    protected static ?string $modelLabel = 'Учитель';

    protected static ?string $pluralModelLabel = 'Учителя';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([

            Forms\Components\TextInput::make('first_name')
                ->label('Имя'),

            Forms\Components\TextInput::make('last_name')
                ->label('Фамилия'),

            Forms\Components\TextInput::make('phone')
                ->label('Телефон'),

            Forms\Components\TextInput::make('email')
                ->label('Email'),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('first_name')
                    ->label('Имя'),

                Tables\Columns\TextColumn::make('last_name')
                    ->label('Фамилия'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Телефон'),

            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTeachers::route('/'),
            'create' => Pages\CreateTeacher::route('/create'),
            'edit' => Pages\EditTeacher::route('/{record}/edit'),
        ];
    }
}