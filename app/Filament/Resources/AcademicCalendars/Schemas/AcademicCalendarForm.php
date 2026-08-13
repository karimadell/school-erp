<?php

namespace App\Filament\Resources\AcademicCalendars\Schemas;

use App\Models\AcademicCalendar;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AcademicCalendarForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('academic_year_id')
                ->label(__('academic_calendar.fields.academic_year'))
                ->relationship('academicYear', 'name', modifyQueryUsing: fn ($query) => $query->where('is_active', true))
                ->searchable()
                ->preload()
                ->required()
                ->unique(table: 'academic_calendars', column: 'academic_year_id', ignoreRecord: true),

            CheckboxList::make('weekly_days_off')
                ->label(__('academic_calendar.fields.weekly_days_off'))
                ->options(array_combine(AcademicCalendar::WEEKDAYS, [
                    __('academic_calendar.weekdays.sun'), __('academic_calendar.weekdays.mon'),
                    __('academic_calendar.weekdays.tue'), __('academic_calendar.weekdays.wed'),
                    __('academic_calendar.weekdays.thu'), __('academic_calendar.weekdays.fri'),
                    __('academic_calendar.weekdays.sat'),
                ]))
                ->default(['fri', 'sat'])
                ->required()
                ->columns(4),

            Select::make('default_bell_schedule_id')
                ->label(__('academic_calendar.fields.default_bell_schedule_id'))
                ->relationship(
                    'defaultBellSchedule',
                    'name',
                    modifyQueryUsing: fn ($query, Get $get) => $query
                        ->where('academic_year_id', $get('academic_year_id'))
                        ->where('is_active', true),
                )
                ->searchable()
                ->preload()
                ->nullable(),
        ]);
    }
}
