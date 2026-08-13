<?php

namespace App\Filament\Resources\BellSchedules\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BellSchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('bell_schedule.fields.name'))->searchable(),
            TextColumn::make('academicYear.name')->label(__('bell_schedule.fields.academic_year'))->sortable(),
            TextColumn::make('shift')->label(__('bell_schedule.fields.shift'))->sortable(),
            TextColumn::make('periods_count')->label(__('bell_schedule.fields.periods'))->counts('periods'),
            IconColumn::make('is_default')->label(__('bell_schedule.fields.is_default'))->boolean(),
            IconColumn::make('is_active')->label(__('bell_schedule.fields.is_active'))->boolean(),
        ])->recordActions([EditAction::make()]);
    }
}
