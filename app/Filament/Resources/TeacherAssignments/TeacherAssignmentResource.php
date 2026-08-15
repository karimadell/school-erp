<?php

namespace App\Filament\Resources\TeacherAssignments;

use App\Filament\Resources\TeacherAssignments\Pages\CreateTeacherAssignment;
use App\Filament\Resources\TeacherAssignments\Pages\EditTeacherAssignment;
use App\Filament\Resources\TeacherAssignments\Pages\ListTeacherAssignments;
use App\Filament\Resources\TeacherAssignments\Schemas\TeacherAssignmentForm;
use App\Filament\Resources\TeacherAssignments\Tables\TeacherAssignmentsTable;
use App\Models\TeacherAssignment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Batch 8: minimal management screen for TeacherAssignment (class x
 * subject x academic year) — the authorization source of truth for the
 * Teacher Portal's record-level scoping. Distinct from teacher_subject
 * (qualification only) and timetables (scheduling only, no academic
 * year); intentionally kept independent of both per approved policy.
 */
class TeacherAssignmentResource extends Resource
{
    protected static ?string $model = TeacherAssignment::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('teacher_assignment.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('teacher_assignment.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('teacher_assignment.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('teacher_assignment.models');
    }

    public static function form(Schema $schema): Schema
    {
        return TeacherAssignmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeacherAssignmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeacherAssignments::route('/'),
            'create' => CreateTeacherAssignment::route('/create'),
            'edit' => EditTeacherAssignment::route('/{record}/edit'),
        ];
    }
}
