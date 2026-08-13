<?php

namespace App\Filament\Resources\AcademicCalendars\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AcademicCalendarsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('academicYear.name')->label(__('academic_calendar.fields.academic_year'))->searchable()->sortable(),
                TextColumn::make('weekly_days_off')
                    ->label(__('academic_calendar.fields.weekly_days_off'))
                    ->formatStateUsing(fn ($state) => collect($state ?? [])->map(fn ($day) => __("academic_calendar.weekdays.{$day}"))->implode(', ')),
                TextColumn::make('events_count')->label(__('academic_calendar.fields.events'))->counts('events'),
                TextColumn::make('updated_at')->label(__('academic_calendar.fields.updated_at'))->dateTime()->sortable(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
