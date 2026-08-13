<?php

namespace App\Filament\Resources\TimetableVersions\Pages;

use App\Filament\Resources\TimetableVersions\TimetableVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTimetableVersions extends ListRecords
{
    protected static string $resource = TimetableVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
