<?php

namespace App\Filament\Resources\Classrooms;

use App\Filament\Resources\Classrooms\Pages\CreateClassroom;
use App\Filament\Resources\Classrooms\Pages\EditClassroom;
use App\Filament\Resources\Classrooms\Pages\ListClassrooms;
use App\Filament\Resources\Classrooms\Schemas\ClassroomForm;
use App\Filament\Resources\Classrooms\Tables\ClassroomsTable;
use App\Models\PhysicalClassroom;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ClassroomResource extends Resource
{
    protected static ?string $model = PhysicalClassroom::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 25;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('classroom.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classroom.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('classroom.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classroom.models');
    }

    public static function form(Schema $schema): Schema
    {
        return ClassroomForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClassroomsTable::configure($table);
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
            'index' => ListClassrooms::route('/'),
            'create' => CreateClassroom::route('/create'),
            'edit' => EditClassroom::route('/{record}/edit'),
        ];
    }
}
