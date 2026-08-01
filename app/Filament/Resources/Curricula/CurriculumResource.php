<?php

namespace App\Filament\Resources\Curricula;

use App\Filament\Resources\Curricula\Pages\CreateCurriculum;
use App\Filament\Resources\Curricula\Pages\EditCurriculum;
use App\Filament\Resources\Curricula\Pages\ListCurricula;
use App\Filament\Resources\Curricula\RelationManagers\StudentSubjectEnrollmentsRelationManager;
use App\Filament\Resources\Curricula\Schemas\CurriculumForm;
use App\Filament\Resources\Curricula\Tables\CurriculaTable;
use App\Models\Curriculum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Item 8 / C1: Academic Year x Grade x Subject, with weekly hours and
 * mandatory/elective/optional-enrichment type. Grade-level only — see
 * Curriculum model doc comment for the deferred Class-level override.
 */
class CurriculumResource extends Resource
{
    protected static ?string $model = Curriculum::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-book-open';

    protected static \UnitEnum|string|null $navigationGroup = 'Учебный процесс';

    protected static ?string $navigationLabel = 'Учебные планы';

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('curriculum.title_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('curriculum.title');
    }

    public static function form(Schema $schema): Schema
    {
        return CurriculumForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CurriculaTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StudentSubjectEnrollmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurricula::route('/'),
            'create' => CreateCurriculum::route('/create'),
            'edit' => EditCurriculum::route('/{record}/edit'),
        ];
    }
}
