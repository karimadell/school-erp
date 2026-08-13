<?php

namespace App\Filament\Resources\AcademicCalendars;

use App\Filament\Resources\AcademicCalendars\Pages\CreateAcademicCalendar;
use App\Filament\Resources\AcademicCalendars\Pages\EditAcademicCalendar;
use App\Filament\Resources\AcademicCalendars\Pages\ListAcademicCalendars;
use App\Filament\Resources\AcademicCalendars\RelationManagers\EventsRelationManager;
use App\Filament\Resources\AcademicCalendars\Schemas\AcademicCalendarForm;
use App\Filament\Resources\AcademicCalendars\Tables\AcademicCalendarsTable;
use App\Models\AcademicCalendar;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class AcademicCalendarResource extends Resource
{
    protected static ?string $model = AcademicCalendar::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 15;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('academic_calendar.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('academic_calendar.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('academic_calendar.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('academic_calendar.models');
    }

    public static function form(Schema $schema): Schema
    {
        return AcademicCalendarForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AcademicCalendarsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [EventsRelationManager::class];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyPermission(['view timetable', 'manage timetable']) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('manage timetable') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('manage timetable') ?? false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAcademicCalendars::route('/'),
            'create' => CreateAcademicCalendar::route('/create'),
            'edit' => EditAcademicCalendar::route('/{record}/edit'),
        ];
    }
}
