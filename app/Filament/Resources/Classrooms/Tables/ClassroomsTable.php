<?php

namespace App\Filament\Resources\Classrooms\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClassroomsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label(__('classroom.fields.code'))->searchable()->sortable(),
            TextColumn::make('name')->label(__('classroom.fields.name'))->searchable(),
            TextColumn::make('academicYear.name')->label(__('classroom.fields.academic_year'))->sortable(),
            TextColumn::make('building')->label(__('classroom.fields.building'))->searchable(),
            TextColumn::make('floor')->label(__('classroom.fields.floor'))->sortable(),
            TextColumn::make('capacity')->label(__('classroom.fields.capacity'))->sortable(),
            TextColumn::make('room_type')->label(__('classroom.fields.room_type'))->badge()
                ->formatStateUsing(fn ($state) => __("classroom.types.{$state}")),
            IconColumn::make('is_active')->label(__('classroom.fields.is_active'))->boolean(),
        ])->recordActions([EditAction::make()]);
    }
}
