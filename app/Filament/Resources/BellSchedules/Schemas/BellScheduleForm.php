<?php

namespace App\Filament\Resources\BellSchedules\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BellScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('academic_year_id')
                ->label(__('bell_schedule.fields.academic_year'))
                ->relationship('academicYear', 'name', modifyQueryUsing: fn ($query) => $query->where('is_active', true))
                ->searchable()->preload()->required(),
            TextInput::make('name')->label(__('bell_schedule.fields.name'))->required()->maxLength(255),
            TextInput::make('shift')->label(__('bell_schedule.fields.shift'))->numeric()->integer()->minValue(1)->default(1)->required(),
            Toggle::make('is_default')->label(__('bell_schedule.fields.is_default'))
                ->helperText(__('bell_schedule.help.default'))->default(false),
            Toggle::make('is_active')->label(__('bell_schedule.fields.is_active'))->default(true),
            Textarea::make('notes')->label(__('bell_schedule.fields.notes'))->columnSpanFull(),
        ]);
    }
}
