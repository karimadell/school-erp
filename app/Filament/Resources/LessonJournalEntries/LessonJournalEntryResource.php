<?php

namespace App\Filament\Resources\LessonJournalEntries;

use App\Filament\Resources\LessonJournalEntries\Pages\CreateLessonJournalEntry;
use App\Filament\Resources\LessonJournalEntries\Pages\EditLessonJournalEntry;
use App\Filament\Resources\LessonJournalEntries\Pages\ListLessonJournalEntries;
use App\Filament\Resources\LessonJournalEntries\Schemas\LessonJournalEntryForm;
use App\Filament\Resources\LessonJournalEntries\Tables\LessonJournalEntriesTable;
use App\Models\LessonJournalEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Batch 9: admin-side oversight of lesson journal entries — gated on
 * 'manage journal entries' (admin/school-admin/principal). Teachers
 * create/edit their own entries through the Teacher Portal's
 * TeacherJournal page instead, authorized via TeacherAssignment.
 */
class LessonJournalEntryResource extends Resource
{
    protected static ?string $model = LessonJournalEntry::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-book-open';

    protected static \UnitEnum|string|null $navigationGroup = 'Учебный процесс';

    protected static ?string $navigationLabel = 'Журнал уроков';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'Запись журнала';

    protected static ?string $pluralModelLabel = 'Журнал уроков';

    public static function form(Schema $schema): Schema
    {
        return LessonJournalEntryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LessonJournalEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLessonJournalEntries::route('/'),
            'create' => CreateLessonJournalEntry::route('/create'),
            'edit' => EditLessonJournalEntry::route('/{record}/edit'),
        ];
    }
}
