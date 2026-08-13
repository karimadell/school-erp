<?php

namespace App\Filament\Resources\BellSchedules;

use App\Filament\Resources\BellSchedules\Pages\CreateBellSchedule;
use App\Filament\Resources\BellSchedules\Pages\EditBellSchedule;
use App\Filament\Resources\BellSchedules\Pages\ListBellSchedules;
use App\Filament\Resources\BellSchedules\RelationManagers\PeriodsRelationManager;
use App\Filament\Resources\BellSchedules\Schemas\BellScheduleForm;
use App\Filament\Resources\BellSchedules\Tables\BellSchedulesTable;
use App\Models\BellSchedule;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class BellScheduleResource extends Resource
{
    protected static ?string $model = BellSchedule::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('bell_schedule.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('bell_schedule.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('bell_schedule.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('bell_schedule.models');
    }

    public static function form(Schema $schema): Schema
    {
        return BellScheduleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BellSchedulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [PeriodsRelationManager::class];
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
            'index' => ListBellSchedules::route('/'),
            'create' => CreateBellSchedule::route('/create'),
            'edit' => EditBellSchedule::route('/{record}/edit'),
        ];
    }
}
