<?php

namespace App\Filament\Resources\TimetableVersions\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TimetableVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('timetable_version.fields.name'))->searchable(),
            TextColumn::make('academicYear.name')->label(__('timetable_version.fields.academic_year'))->sortable(),
            TextColumn::make('status')->label(__('timetable_version.fields.status'))->badge()
                ->formatStateUsing(fn ($state) => __("timetable_version.statuses.{$state}")),
            TextColumn::make('effective_from')->label(__('timetable_version.fields.effective_from'))->date()->sortable(),
            TextColumn::make('effective_to')->label(__('timetable_version.fields.effective_to'))->date()->sortable(),
            TextColumn::make('publisher.name')->label(__('timetable_version.fields.published_by')),
            TextColumn::make('published_at')->label(__('timetable_version.fields.published_at'))->dateTime(),
        ])->recordActions([EditAction::make()->visible(fn ($record) => $record->status === 'draft')]);
    }
}
