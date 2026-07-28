<?php

namespace App\Filament\Resources\TeacherAssignments\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TeacherAssignmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('teacher.full_name')
                    ->label('Учитель')
                    ->searchable(['teacher.first_name', 'teacher.last_name'])
                    ->sortable(),

                TextColumn::make('schoolClass.name_ru')
                    ->label('Класс')
                    ->sortable(),

                TextColumn::make('subject.name_ru')
                    ->label('Предмет')
                    ->sortable(),

                TextColumn::make('academicYear.name')
                    ->label('Учебный год')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->filters([

                SelectFilter::make('academic_year_id')
                    ->label('Учебный год')
                    ->relationship('academicYear', 'name'),

                SelectFilter::make('class_id')
                    ->label('Класс')
                    ->relationship('schoolClass', 'name_ru'),

            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
