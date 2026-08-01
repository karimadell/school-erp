<?php

namespace App\Filament\Resources\StudentGrades;

use App\Filament\Resources\StudentGrades\Pages\CreateStudentGrade;
use App\Filament\Resources\StudentGrades\Pages\EditStudentGrade;
use App\Filament\Resources\StudentGrades\Pages\ListStudentGrades;
use App\Filament\Resources\StudentGrades\Pages\ViewStudentGrade;

use App\Filament\Resources\StudentGrades\Schemas\StudentGradeForm;
use App\Filament\Resources\StudentGrades\Schemas\StudentGradeInfolist;
use App\Filament\Resources\StudentGrades\Tables\StudentGradesTable;

use App\Models\StudentGrade;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

use BackedEnum;
use UnitEnum;

class StudentGradeResource extends Resource
{
    protected static ?string $model = StudentGrade::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Оценки';

    protected static ?string $modelLabel = 'Оценка';

    protected static ?string $pluralModelLabel = 'Оценки';

    protected static string|UnitEnum|null $navigationGroup = 'Учебный процесс';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return StudentGradeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StudentGradeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudentGradesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentGrades::route('/'),
            'create' => CreateStudentGrade::route('/create'),
            'view' => ViewStudentGrade::route('/{record}'),
            'edit' => EditStudentGrade::route('/{record}/edit'),
        ];
    }
}
