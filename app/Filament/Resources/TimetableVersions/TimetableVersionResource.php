<?php

namespace App\Filament\Resources\TimetableVersions;

use App\Filament\Resources\TimetableVersions\Pages\CreateTimetableVersion;
use App\Filament\Resources\TimetableVersions\Pages\EditTimetableVersion;
use App\Filament\Resources\TimetableVersions\Pages\ListTimetableVersions;
use App\Filament\Resources\TimetableVersions\RelationManagers\EntriesRelationManager;
use App\Filament\Resources\TimetableVersions\Schemas\TimetableVersionForm;
use App\Filament\Resources\TimetableVersions\Tables\TimetableVersionsTable;
use App\Models\TimetableVersion;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class TimetableVersionResource extends Resource
{
    protected static ?string $model = TimetableVersion::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('timetable_version.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('timetable_version.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('timetable_version.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('timetable_version.models');
    }

    public static function form(Schema $schema): Schema
    {
        return TimetableVersionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TimetableVersionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [EntriesRelationManager::class];
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
        return (auth()->user()?->can('manage timetable') ?? false)
            && $record->status === TimetableVersion::STATUS_DRAFT;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTimetableVersions::route('/'),
            'create' => CreateTimetableVersion::route('/create'),
            'edit' => EditTimetableVersion::route('/{record}/edit'),
        ];
    }
}
