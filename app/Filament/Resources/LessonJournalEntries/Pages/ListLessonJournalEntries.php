<?php

namespace App\Filament\Resources\LessonJournalEntries\Pages;

use App\Filament\Resources\LessonJournalEntries\LessonJournalEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLessonJournalEntries extends ListRecords
{
    protected static string $resource = LessonJournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
