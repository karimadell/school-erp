<?php

namespace App\Filament\Resources\LessonJournalEntries\Pages;

use App\Filament\Resources\LessonJournalEntries\LessonJournalEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLessonJournalEntry extends CreateRecord
{
    protected static string $resource = LessonJournalEntryResource::class;
}
