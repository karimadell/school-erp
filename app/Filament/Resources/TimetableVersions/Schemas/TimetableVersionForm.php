<?php

namespace App\Filament\Resources\TimetableVersions\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TimetableVersionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('academic_year_id')
                ->label(__('timetable_version.fields.academic_year'))
                ->relationship('academicYear', 'name', modifyQueryUsing: fn ($query) => $query->where('is_active', true))
                ->searchable()->preload()->required(),
            TextInput::make('name')->label(__('timetable_version.fields.name'))->required()->maxLength(255),
            DatePicker::make('effective_from')->label(__('timetable_version.fields.effective_from'))->required(),
            DatePicker::make('effective_to')->label(__('timetable_version.fields.effective_to'))
                ->afterOrEqual('effective_from')->required(),
            Textarea::make('notes')->label(__('timetable_version.fields.notes'))->columnSpanFull(),
        ]);
    }
}
