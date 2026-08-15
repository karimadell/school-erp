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
                    ->label(__('teacher_assignment.fields.teacher'))
                    ->searchable(['teacher.first_name', 'teacher.last_name'])
                    ->sortable(),

                TextColumn::make('schoolClass.name_ru')
                    ->label(__('teacher_assignment.fields.class'))
                    ->sortable(),

                TextColumn::make('subject.name_ru')
                    ->label(__('teacher_assignment.fields.subject'))
                    ->sortable(),

                TextColumn::make('academicYear.name')
                    ->label(__('teacher_assignment.fields.academic_year'))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('teacher_assignment.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->filters([

                SelectFilter::make('academic_year_id')
                    ->label(__('teacher_assignment.filters.academic_year'))
                    ->relationship('academicYear', 'name'),

                SelectFilter::make('class_id')
                    ->label(__('teacher_assignment.filters.class'))
                    ->relationship('schoolClass', 'name_ru'),

            ])
            ->emptyStateHeading(__('teacher_assignment.empty_heading'))
            ->emptyStateDescription(__('teacher_assignment.empty_description'))
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
