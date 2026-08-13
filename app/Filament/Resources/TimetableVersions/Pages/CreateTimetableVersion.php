<?php

namespace App\Filament\Resources\TimetableVersions\Pages;

use App\Filament\Resources\TimetableVersions\TimetableVersionResource;
use App\Models\TimetableVersion;
use Filament\Resources\Pages\CreateRecord;

class CreateTimetableVersion extends CreateRecord
{
    protected static string $resource = TimetableVersionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = TimetableVersion::STATUS_DRAFT;

        return $data;
    }
}
