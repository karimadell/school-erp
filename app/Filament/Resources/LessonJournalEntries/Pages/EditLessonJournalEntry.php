<?php

namespace App\Filament\Resources\LessonJournalEntries\Pages;

use App\Filament\Resources\LessonJournalEntries\LessonJournalEntryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLessonJournalEntry extends EditRecord
{
    protected static string $resource = LessonJournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
